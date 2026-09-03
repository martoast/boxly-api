<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Jobs\ProcessLiveShoppingResultJob;
use App\Services\LiveShoppingEngine;
use App\Services\LiveShoppingEngineException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Live Shopping — the customer-facing control plane. Laravel never proxies,
 * terminates or sees video; it creates a session, reports it, and mints viewer
 * tickets by asking the engine.
 *
 * Never reports a session as running when the engine did not confirm it, and
 * never resurrects a session the reconciler has already released.
 */
class LiveShoppingController extends Controller
{
    /** The one caveat a COMPLETED session may expose (engine partial_match). */
    private const COMPLETED_CAVEAT = 'partial_match';

    public function __construct(private readonly LiveShoppingEngine $engine)
    {
    }

    public function store(Request $request)
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }

        // Remote store browser: `kind` selects who drives the session. `manual`
        // (the customer controls the streamed store) needs no conversation and
        // no objective — there is no assistant result to land anywhere.
        $kind = $request->input('kind', LiveShoppingSession::KIND_AGENT);
        if (! in_array($kind, LiveShoppingSession::KINDS, true)) {
            return response()->json(['success' => false, 'code' => 'invalid_kind', 'message' => 'kind must be agent or manual.'], 422);
        }
        $manual = $kind === LiveShoppingSession::KIND_MANUAL;
        $validated = $request->validate([
            // Required for agent sessions: EngineV1 is conversation-attached end
            // to end, and a session with no conversation has nowhere to land its
            // result. Manual sessions carry none.
            'conversation_id' => $manual ? 'nullable|integer' : 'required|integer',
            'objective'       => $manual ? 'prohibited' : 'required|string|max:500',
            'store_ids'       => $manual ? 'prohibited' : ['sometimes', 'array', 'min:1', 'max:4'],
            // Shape only. No retailer allowlist: the engine owns which stores
            // exist, and a list here would need editing every time it learns one.
            'store_id'        => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
            // L2 (multi-store): an optional ordered list whose first entry is
            // store_id; distinct slugs; bounded by the engine's advertised cap.
            'store_ids.*'     => ['string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
        ]);
        $storeIds = array_values($validated['store_ids'] ?? [$validated['store_id']]);
        if ($storeIds[0] !== $validated['store_id'] || count(array_unique($storeIds)) !== count($storeIds)) {
            return response()->json(['success' => false, 'code' => 'invalid_store_ids', 'message' => 'store_ids must be distinct and start with store_id.'], 422);
        }
        if (count($storeIds) > 1) {
            try {
                $cap = $this->engine->maxStoresPerSession();
            } catch (LiveShoppingEngineException $e) {
                return response()->json(['success' => false, 'code' => $this->publicCode($e), 'message' => $e->customer()], $e->status);
            }
            if (count($storeIds) > $cap) {
                return response()->json(['success' => false, 'code' => 'too_many_stores', 'message' => 'This engine opens at most ' . $cap . ' store(s) per live session.'], 422);
            }
        }

        $conversation = null;
        if (! $manual || ! empty($validated['conversation_id'])) {
            $conversation = Conversation::find($validated['conversation_id']);
            if (! $conversation || $conversation->user_id !== $request->user()->id) {
                // Same answer either way: the existence of someone else's thread is
                // not ours to confirm.
                abort(403, 'Not your conversation.');
            }
        }
        $objective = $manual ? LiveShoppingSession::KIND_MANUAL : $validated['objective'];

        // Claim the one active slot at INSERT. No precheck: two concurrent
        // creates would both read "none" and both insert. The unique index on
        // (user_id, active_slot) is the arbiter.
        try {
            $session = LiveShoppingSession::create([
                'user_id'         => $request->user()->id,
                'conversation_id' => $conversation?->id,
                'status'          => LiveShoppingSession::STATUS_PENDING,
                'store_id'        => $storeIds[0],
                'kind'            => $kind,
                'stores'          => array_map(fn ($id) => ['id' => $id], $storeIds),
                'objective'       => $objective,
                'active_slot'     => 1,
            ]);
        } catch (QueryException $e) {
            // ONLY the active-slot collision is a 409. Any other database
            // failure is a real fault and must not be disguised as "you already
            // have a session running".
            if (! $this->isActiveSlotCollision($e)) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => 'You already have a live shopping session running.',
            ], 409);
        }

        try {
            $engineSession = $this->engine->createSession(
                $session->id,
                $conversation?->id,
                $storeIds,
                $objective,
                $kind,
            );
        } catch (LiveShoppingEngineException $e) {
            // A store the engine does not know is NOT an outage: the row and the
            // response both say so, so the assistant can offer a supported store
            // instead of "try again later".
            $code = $this->publicCode($e);
            $this->releaseSlot($session, $code === 'store_unsupported' ? 'store_unsupported' : 'engine_unavailable');

            return response()->json(['success' => false, 'code' => $code, 'message' => $e->customer()], $e->status);
        }

        // COMPARE-AND-SET, not a blind save on a stale model.
        //
        // While the create was in flight the reconciler may have expired this
        // row (it had no deadline yet) and released active_slot. A plain
        // ->save() would then write status=running back over a released row,
        // producing a live session that sits OUTSIDE the one-active constraint —
        // the exact invariant this feature is built on.
        $claimed = LiveShoppingSession::where('id', $session->id)
            ->where('status', LiveShoppingSession::STATUS_PENDING)
            ->where('active_slot', 1)
            ->whereNull('expires_at')
            ->update([
                'engine_session_id' => $engineSession['id'],
                // rev 32b: `running` only when the engine confirmed its worker; a
                // `starting` answer keeps the row pending (with the engine id and
                // deadline, so the ticket route and the reaper treat it as live)
                // until a status read sees running or a terminal arrives.
                'status'            => $engineSession['status'] === 'running'
                    ? LiveShoppingSession::STATUS_RUNNING
                    : LiveShoppingSession::STATUS_PENDING,
                // Parsed, NOT passed through as the engine's RFC3339 string.
                // This is a query-builder update, so Eloquent's `datetime` cast
                // never runs — the raw value goes to the driver. SQLite stores
                // "2026-09-01T12:34:56Z" happily because it is dynamically
                // typed; MySQL rejects it outright (1292 Incorrect datetime
                // value), which would make EVERY successful create a 500 in
                // production while the SQLite suite stayed green.
                'expires_at'        => Carbon::parse($engineSession['expires_at']),
                'latest_seq'        => $engineSession['latest_seq'],
                'updated_at'        => now(),
            ]);

        if ($claimed === 0) {
            // We lost. Do NOT resurrect the row locally. Tell the engine to drop
            // the session it just accepted (best effort — its own deadline bounds
            // it anyway) and answer with a stable conflict.
            Log::warning('live-shopping create lost the race to persist', [
                'session_id'        => $session->id,
                'engine_session_id' => $engineSession['id'],
            ]);

            $this->engine->cancelSessionQuietly($engineSession['id']);

            return response()->json([
                'success' => false,
                'message' => 'That live shopping session expired before it could start. Please try again.',
            ], 409);
        }

        return response()->json(['success' => true, 'data' => $this->present($session->fresh())], 201);
    }

    public function show(Request $request, LiveShoppingSession $session)
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $this->authorizeOwner($request, $session);

        $this->reconcileEngineTerminal($session);

        return response()->json(['success' => true, 'data' => $this->present($session->fresh())]);
    }

    /**
     * POST, not GET: minting a ticket is a state-changing engine round-trip that
     * issues a short-lived credential. It must never be cached, prefetched or
     * replayed from a link.
     */
    public function ticket(Request $request, LiveShoppingSession $session)
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $this->authorizeOwner($request, $session);

        // A ticket for a finished session is a ticket that cannot connect.
        if ($session->isTerminal() || ! $session->engine_session_id) {
            return response()->json([
                'success' => false,
                'message' => 'This session is no longer live.',
            ], 409);
        }

        try {
            // Only the customer-driven session gets an input plane; the agent's
            // session stays view-only.
            $ticket = $this->engine->viewerTicket($session->engine_session_id, $request->user()->id, $session->kind === LiveShoppingSession::KIND_MANUAL);
        } catch (LiveShoppingEngineException $e) {
            return response()->json(['success' => false, 'message' => $e->customer()], $e->status);
        }

        // Minted per request, passed through verbatim, never persisted.
        return response()->json(['success' => true, 'data' => $ticket]);
    }

    /** Copied verbatim in shape from ConversationController::authorizeOwner. */
    private function authorizeOwner(Request $request, LiveShoppingSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 403, 'Not your session.');
    }

    /**
     * Release the slot without ever inventing an engine_session_id. Conditional
     * for the same reason as the claim above: the reconciler may already have
     * moved this row, and we must not overwrite its verdict.
     */
    /**
     * The stores the engine can open live: the single routing source for the
     * assistant (see LiveShoppingEngine::catalog). Same 503 gate as every other
     * live-shopping route when the feature is off.
     */
    public function stores()
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }

        try {
            return response()->json(['success' => true, 'stores' => $this->engine->catalog(), 'max_stores_per_session' => $this->engine->maxStoresPerSession()]);
        } catch (LiveShoppingEngineException $e) {
            return response()->json(['success' => false, 'code' => $this->publicCode($e), 'message' => $e->customer()], $e->status);
        }
    }

    /**
     * The closed set of codes a client may see. The exception message is an
     * upstream-influenced slug (LiveShoppingEngine::refused passes the engine's
     * code through), so it is mapped, never echoed.
     */
    private function publicCode(LiveShoppingEngineException $e): string
    {
        return match ($e->getMessage()) {
            'store_unsupported' => 'store_unsupported',
            'not_configured'    => 'not_configured',
            'rate_limited'      => 'rate_limited',
            'media_unavailable' => 'media_unavailable',
            default             => $e->status === 422 ? 'engine_refused' : 'engine_unavailable',
        };
    }

    private function releaseSlot(LiveShoppingSession $session, string $errorCode): void
    {
        LiveShoppingSession::where('id', $session->id)
            ->whereIn('status', LiveShoppingSession::ACTIVE_STATUSES)
            ->update([
                'status'      => LiveShoppingSession::STATUS_FAILED,
                'error_code'  => $errorCode,
                'active_slot' => null,
                'updated_at'  => now(),
            ]);
    }

    /**
     * The browser's status GET must also repair the durable Laravel gate. A
     * webhook may be delayed or lost after the engine has durably terminalized.
     * The engine status includes the same validated terminal result as the
     * webhook. We materialize a deterministic local receipt and run the same
     * terminal projector, so completed results cannot release the slot while
     * silently losing their conversation message.
     */
    private function reconcileEngineTerminal(LiveShoppingSession $session): void
    {
        if ($session->isTerminal() || ! $session->engine_session_id) {
            return;
        }

        try {
            $remote = $this->engine->sessionStatus($session->engine_session_id);
        } catch (LiveShoppingEngineException $e) {
            return; // transport/schema failure is not authority to mutate local state
        }

        if ($remote['id'] !== $session->engine_session_id
            || $remote['conversation_id'] !== (string) $session->conversation_id
            || $remote['store_id'] !== $session->store_id) {
            return;
        }
        // rev 32b: a pending row whose engine session is now running becomes
        // running here (status only; nothing else about the row changes).
        if ($remote['status'] === 'running' && $session->status === LiveShoppingSession::STATUS_PENDING) {
            LiveShoppingSession::where('id', $session->id)
                ->where('status', LiveShoppingSession::STATUS_PENDING)
                ->update(['status' => LiveShoppingSession::STATUS_RUNNING, 'updated_at' => now()]);
            $session->refresh();

            return;
        }
        if (! in_array($remote['status'], LiveShoppingSession::TERMINAL_STATUSES, true)
            || $session->latest_seq !== null && $remote['latest_seq'] <= $session->latest_seq) {
            return;
        }

        $products = $remote['products'];
        $payload = [
            'delivery_id' => 'status-reconcile-' . $remote['id'] . '-' . $remote['latest_seq'],
            'session_id' => $remote['id'],
            'conversation_id' => (string) $session->conversation_id,
            'terminal_seq' => $remote['latest_seq'],
            'occurred_at' => now()->toIso8601String(),
            'result' => array_merge(
                ['outcome' => $remote['status'], 'products' => $products, 'error_code' => $remote['error_code']],
                $remote['stores'] !== null ? ['stores' => $remote['stores']] : []
            ),
            // The EXACT part shape the webhook projector persists: the caveat
            // rides on the part when the engine's terminal carries it, so the
            // persisted gallery says so whichever projector wins the race.
            'assistant_part' => ['type' => 'tool-live_results', 'state' => 'output-available', 'output' => array_merge(
                ['products' => $products],
                $remote['error_code'] === self::COMPLETED_CAVEAT ? ['caveat' => self::COMPLETED_CAVEAT] : [],
                $remote['stores'] !== null ? ['stores' => $remote['stores']] : []
            )],
        ];
        $receipt = LiveShoppingWebhookReceipt::firstOrCreate(
            ['delivery_id' => $payload['delivery_id']],
            [
                'content_sha256' => hash('sha256', json_encode($payload)), 'payload' => $payload,
                'status' => LiveShoppingWebhookReceipt::STATUS_RECEIVED,
                'terminal_seq' => $payload['terminal_seq'], 'outcome' => $remote['status'],
                'received_at' => now(),
            ]
        );

        // ProcessLiveShoppingResultJob is the single terminal projector used by
        // both webhook delivery and status reconciliation. Its row locks/CAS
        // make concurrent show requests and a real webhook idempotent.
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
    }

    /**
     * Distinguish the active-slot unique violation from every other query error.
     *
     * Matching on the driver's message is unpleasant but it is what both MySQL
     * (errno 1062) and SQLite (SQLSTATE 23000) give us, and the alternative —
     * treating every QueryException as "already running" — would hide real
     * faults behind a friendly 409.
     */
    private function isActiveSlotCollision(QueryException $e): bool
    {
        $message = $e->getMessage();

        return ($e->getCode() === '23000' || str_contains($message, '1062'))
            && (str_contains($message, 'active_slot') || str_contains($message, 'live_shopping_sessions'));
    }

    /**
     * Disabled, unconfigured, or migrations not yet run all answer the same
     * honest 503. The table guard is CLAUDE.md's deploy-window rule: a read path
     * querying a brand-new table must not 500 while the migration catches up.
     */
    private function disabled()
    {
        if (! $this->engine->enabled() || ! Schema::hasTable('live_shopping_sessions')) {
            return response()->json([
                'success' => false,
                'message' => 'live shopping is not configured',
            ], 503);
        }

        return null;
    }

    private function present(LiveShoppingSession $session): array
    {
        return [
            'id'                => $session->id,
            'status'            => $session->status,
            'engine_session_id' => $session->engine_session_id,
            'conversation_id'   => $session->conversation_id,
            'store_id'          => $session->store_id,
            // Remote store browser: who drives the session (agent | manual).
            'kind'              => $session->kind ?? LiveShoppingSession::KIND_AGENT,
            'expires_at'        => optional($session->expires_at)->toIso8601String(),
            'created_at'        => optional($session->created_at)->toIso8601String(),
            'updated_at'        => optional($session->updated_at)->toIso8601String(),
            'error_code'        => $this->publicErrorCode($session),
            // L2 (multi-store): one entry per requested store, in request order.
            'stores'            => $this->presentStores($session),
        ];
    }

    /**
     * L2: per-store view of the session. Before a terminal every store shares
     * the session's status; after it each entry carries the outcome the engine
     * reported for that store (persisted on the row's `stores` json by the
     * result job / the status reconcile), sanitized like the session code.
     */
    private function presentStores(LiveShoppingSession $session): array
    {
        $out = [];
        foreach ((array) $session->stores as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id)) {
                continue;
            }
            $outcome = is_array($entry) && is_string($entry['outcome'] ?? null) ? $entry['outcome'] : null;
            $status = $outcome ?? $session->status;
            $code = null;
            if ($status === LiveShoppingSession::STATUS_COMPLETED) {
                $code = ($entry['error_code'] ?? null) === self::COMPLETED_CAVEAT ? self::COMPLETED_CAVEAT : null;
            } elseif ($status === LiveShoppingSession::STATUS_FAILED) {
                $raw = (string) ($entry['error_code'] ?? ($outcome === null ? $session->error_code : ''));
                $code = preg_match('/^[a-z0-9_]{1,40}$/', $raw) === 1 ? $raw : 'failed';
            }
            $out[] = ['id' => $id, 'status' => $status, 'error_code' => $code];
        }

        return $out;
    }

    /**
     * Bounded public terminal reason. A FAILED session exposes its reason as a
     * closed machine slug — anything else stored (hostile, oversized, or absent)
     * presents as the literal 'failed'. A COMPLETED session exposes exactly one
     * caveat, 'partial_match' (the engine verified a product that misses a
     * requested constraint), and nothing else; every other status is null. The
     * sanitizer here is a guarantee about this surface, not an observation
     * about today's writers.
     */
    private function publicErrorCode(LiveShoppingSession $session): ?string
    {
        if ($session->status === LiveShoppingSession::STATUS_COMPLETED) {
            return $session->error_code === self::COMPLETED_CAVEAT ? self::COMPLETED_CAVEAT : null;
        }

        if ($session->status !== LiveShoppingSession::STATUS_FAILED) {
            return null;
        }

        $code = (string) $session->error_code;

        return preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : 'failed';
    }
}
