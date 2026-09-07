<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Support\ProductV1;
use Illuminate\Http\Request;

/**
 * Chat threads for the AI shopping assistant — history sidebar + resume.
 * All actions are scoped to the authenticated user.
 */
class ConversationController extends Controller
{
    /** Max rows one summarizer run may fold (bounds the aux-model input). */
    private const FOLD_CAP = 60;

    /** Hard cap on the stored running summary (~400 tokens). */
    private const SUMMARY_MAX_CHARS = 2500;

    public function index(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->limit(100)
            ->get(['id', 'title', 'last_message_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? null,
            'last_message_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $conversation], 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        // Paginated newest-first with a cursor: return the latest $limit
        // messages (or the $limit just before $before), serve them ascending,
        // and tell the client whether older ones remain.
        $limit = min(max((int) $request->query('limit', 30), 1), 100);
        $before = $request->query('before');

        // reorder() clears the relation's default orderBy('id') so newest-first
        // pagination actually takes effect.
        $query = $conversation->messages()->reorder('id', 'desc');
        if ($before) {
            $query->where('id', '<', (int) $before);
        }
        $rows = $query->limit($limit + 1)->get(['id', 'role', 'content', 'created_at']);

        $hasMore = $rows->count() > $limit;
        $messages = $rows->take($limit)->sortBy('id')->values();

        // Single source of truth: every product ever shown in this chat,
        // deduped, so the assistant can re-display earlier items even after the
        // message window is paginated. Only on the first page (no cursor).
        $products = $before ? [] : $this->deriveProducts($conversation);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $messages,
                'has_more' => $hasMore,
                'products' => $products,
            ],
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $validated = $request->validate(['title' => 'required|string|max:255']);
        $conversation->update(['title' => $validated['title']]);

        return response()->json(['success' => true, 'data' => $conversation]);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Append one or more messages to a thread (used by the chat backend per turn).
     * Auto-titles the thread from the first user message if still untitled.
     */
    public function addMessages(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required',
        ]);

        $insertedIds = [];
        foreach ($validated['messages'] as $m) {
            $row = $conversation->messages()->create([
                'role' => $m['role'],
                'content' => $m['content'],
            ]);
            $insertedIds[] = $row->id;

            if (! $conversation->title && $m['role'] === 'user') {
                $conversation->title = $this->titleFrom($m['content']);
            }
        }

        $conversation->last_message_at = now();
        $conversation->save();

        // The caller (the chat backend's persistTurn) only needs to know the turn
        // landed. This used to return the WHOLE thread (fresh('messages')), which
        // grew with every turn of a long chat for nothing — no client read it.
        return response()->json(['success' => true, 'data' => [
            'id'              => $conversation->id,
            'title'           => $conversation->title,
            'last_message_at' => $conversation->last_message_at,
            'inserted_ids'    => $insertedIds,
            'last_message_id' => $insertedIds ? max($insertedIds) : $conversation->messages()->max('id'),
        ]]);
    }

    /**
     * Per-chat rolling memory, READ side. Returns the running summary plus what is
     * still unsummarized. With ?window=N it also returns the rows the summarizer
     * should fold next: everything after summary_upto EXCEPT the trailing N rows
     * (those are still shown verbatim in the prompt, so they are not folded yet).
     * Choosing the rows here, by database id, is what keeps the summary exact —
     * the chat backend never has to map its in-flight client message ids.
     */
    public function context(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $window = max((int) $request->query('window', 0), 0);
        $upto = (int) ($conversation->summary_upto_message_id ?? 0);

        $lastId = (int) ($conversation->messages()->max('id') ?? 0);
        $unsummarized = $conversation->messages()->where('id', '>', $upto)->count();

        $toFold = [];
        if ($window > 0 && $unsummarized > $window) {
            // Rows after `upto`, ascending, minus the trailing `window` rows; capped so
            // one summarizer run never swallows an unbounded backlog.
            $toFold = $conversation->messages()
                ->where('id', '>', $upto)
                ->reorder('id')
                ->limit(min($unsummarized - $window, self::FOLD_CAP))
                ->get(['id', 'role', 'content'])
                ->values()
                ->all();
        }

        return response()->json(['success' => true, 'data' => [
            'id'                      => $conversation->id,
            'running_summary'         => $conversation->running_summary,
            'summary_upto_message_id' => $conversation->summary_upto_message_id,
            'summary_version'         => (int) $conversation->summary_version,
            'summary_updated_at'      => $conversation->summary_updated_at,
            'last_message_id'         => $lastId ?: null,
            'unsummarized'            => $unsummarized,
            'to_fold'                 => $toFold,
        ]]);
    }

    /**
     * Per-chat rolling memory, WRITE side (the chat backend's summarizer).
     * Optimistic: the caller sends the version it read; a stale version means a
     * concurrent turn already advanced the summary → 409, and the caller drops its
     * result (the next turn re-folds from the newer upto).
     */
    public function updateSummary(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $validated = $request->validate([
            'running_summary'         => 'required|string|max:'.self::SUMMARY_MAX_CHARS,
            'summary_upto_message_id' => 'required|integer|min:1',
            'base_version'            => 'required|integer|min:0',
        ]);

        $upto = (int) $validated['summary_upto_message_id'];
        $exists = $conversation->messages()->where('id', $upto)->exists();
        if (! $exists) {
            return response()->json(['success' => false, 'error' => 'upto_not_in_conversation'], 422);
        }
        if ($upto < (int) ($conversation->summary_upto_message_id ?? 0)) {
            return response()->json(['success' => false, 'error' => 'upto_moves_backwards'], 422);
        }

        // Atomic compare-and-set on the version column.
        $updated = Conversation::whereKey($conversation->id)
            ->where('summary_version', (int) $validated['base_version'])
            ->update([
                'running_summary'         => $validated['running_summary'],
                'summary_upto_message_id' => $upto,
                'summary_version'         => (int) $validated['base_version'] + 1,
                'summary_updated_at'      => now(),
            ]);

        if (! $updated) {
            return response()->json([
                'success'         => false,
                'error'           => 'version_conflict',
                'summary_version' => (int) $conversation->fresh()->summary_version,
            ], 409);
        }

        return response()->json(['success' => true, 'data' => [
            'summary_version'         => (int) $validated['base_version'] + 1,
            'summary_upto_message_id' => $upto,
        ]]);
    }

    /**
     * Claim a guest's in-progress conversation into the now-authenticated
     * account as a new thread (called right after chat-register).
     */
    public function claim(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? $this->titleFrom($validated['messages'][0]['content'] ?? 'New chat'),
            'last_message_at' => now(),
        ]);

        foreach ($validated['messages'] as $m) {
            $conversation->messages()->create(['role' => $m['role'], 'content' => $m['content']]);
        }

        return response()->json(['success' => true, 'data' => $conversation->load('messages')], 201);
    }

    private function authorizeOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 403, 'Not your conversation.');
    }

    /**
     * Deduplicated registry of every product shown in this conversation, scanned
     * from all message parts (tool outputs). Each product gets a stable id (FNV
     * hash of its URL) so the assistant can reference/re-display it later.
     */
    private function deriveProducts(Conversation $conversation): array
    {
        $seen = [];
        $out = [];
        foreach ($conversation->messages()->get(['content']) as $m) {
            $parts = is_array($m->content) ? ($m->content['parts'] ?? []) : [];
            if (! is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                $prods = $part['output']['products'] ?? null;
                if (! is_array($prods)) {
                    continue;
                }
                foreach ($prods as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $url = $p['url'] ?? $p['product_url'] ?? null;
                    $title = $p['title'] ?? $p['name'] ?? null;
                    if (! $title && ! $url) {
                        continue;
                    }
                    $id = $this->productId($url ?: ($title . ($p['store'] ?? '')));
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $img = $p['image'] ?? $p['image_url'] ?? null;
                    if (is_string($img) && str_starts_with($img, 'data:')) {
                        $img = null; // never carry base64 in the registry
                    }
                    // Live-shopping results arrive as ProductV1, whose money
                    // lives in {amount, currency} objects rather than the
                    // scalar price/was this rail reads. Without this the rail
                    // renders priceless; passing the object straight through
                    // would render "[object Object]", which is worse because it
                    // looks like data. Legacy keys always win, so parts written
                    // by every existing tool are untouched.
                    $money = ProductV1::money($p);

                    $out[] = [
                        'id'      => $id,
                        'title'   => $title,
                        'store'   => $p['store'] ?? null,
                        'price'   => $p['price'] ?? $p['price_usd'] ?? $money['price'],
                        'was'     => $p['was'] ?? $money['was'],
                        'on_sale' => $p['on_sale'] ?? $money['on_sale'],
                        'image'   => $img,
                        'url'     => $url,
                        'snippet' => $p['snippet'] ?? null,
                        'token'   => $p['token'] ?? null,
                    ];
                }
            }
        }
        return array_slice($out, -80); // cap the registry
    }

    /** FNV-1a 32-bit → base36, MUST match the JS implementation in the chat. */
    private function productId(string $s): string
    {
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return 'p' . base_convert((string) $h, 10, 36);
    }

    private function titleFrom($content): string
    {
        $text = '';

        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            if (isset($content['text']) && is_string($content['text'])) {
                $text = $content['text'];
            } elseif (isset($content['parts']) && is_array($content['parts'])) {
                // Frontend sends { parts: [{ type: 'text', text: '...' }, ...] } —
                // use the first non-empty text part for the thread title.
                foreach ($content['parts'] as $part) {
                    if (($part['type'] ?? null) === 'text' && ! empty($part['text'])) {
                        $text = $part['text'];
                        break;
                    }
                }
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));

        return mb_substr($text, 0, 60) ?: 'Nuevo chat';
    }
}
