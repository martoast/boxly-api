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

        foreach ($validated['messages'] as $m) {
            $conversation->messages()->create([
                'role' => $m['role'],
                'content' => $m['content'],
            ]);

            if (! $conversation->title && $m['role'] === 'user') {
                $conversation->title = $this->titleFrom($m['content']);
            }
        }

        $conversation->last_message_at = now();
        $conversation->save();

        return response()->json(['success' => true, 'data' => $conversation->fresh('messages')]);
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
