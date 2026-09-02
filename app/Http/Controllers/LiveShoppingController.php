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
    public function __construct(private readonly LiveShoppingEngine $engine)
    {
    }

    public function store(Request $request)
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }

        $validated = $request->validate([
            // Required: EngineV1 is conversation-attached end to end, and a
            // session with no conversation has nowhere to land its result.
            'conversation_id' => 'required|integer',
            'objective'       => 'required|string|max:500',
            // Shape only. No retailer allowlist: the engine owns which stores
            // exist, and a list here would need editing every time it learns one.
            'store_id'        => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
        ]);

        $conversation = Conversation::find($validated['conversation_id']);
        if (! $conversation || $conversation->user_id !== $request->user()->id) {
            // Same answer either way: the existence of someone else's thread is
            // not ours to confirm.
            abort(403, 'Not your conversation.');
        }

        // Claim the one active slot at INSERT. No precheck: two concurrent
        // creates would both read "none" and both insert. The unique index on
        // (user_id, active_slot) is the arbiter.
        try {
            $session = LiveShoppingSession::create([
                'user_id'         => $request->user()->id,
                'conversation_id' => $conversation->id,
                'status'          => LiveShoppingSession::STATUS_PENDING,
                'store_id'        => $validated['store_id'],
                'stores'          => [['id' => $validated['store_id']]],
                'objective'       => $validated['objective'],
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
                $conversation->id,
                $validated['store_id'],
                $validated['objective'],
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
                'status'            => LiveShoppingSession::STATUS_RUNNING,
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
            $ticket = $this->engine->viewerTicket($session->engine_session_id, $request->user()->id);
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
            return response()->json(['success' => true, 'stores' => $this->engine->catalog()]);
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
            || $remote['store_id'] !== $session->store_id
            || ! in_array($remote['status'], LiveShoppingSession::TERMINAL_STATUSES, true)
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
            'result' => ['outcome' => $remote['status'], 'products' => $products, 'error_code' => $remote['error_code']],
            'assistant_part' => ['type' => 'tool-live_results', 'state' => 'output-available', 'output' => ['products' => $products]],
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
            'expires_at'        => optional($session->expires_at)->toIso8601String(),
            'created_at'        => optional($session->created_at)->toIso8601String(),
            'updated_at'        => optional($session->updated_at)->toIso8601String(),
            'error_code'        => $this->publicErrorCode($session),
        ];
    }

    /**
     * Bounded public terminal reason. Only a FAILED session exposes one, and
     * only as a closed machine slug — anything else stored (hostile, oversized,
     * or absent) presents as the literal 'failed'. The sanitizer here is a
     * guarantee about this surface, not an observation about today's writers.
     */
    private function publicErrorCode(LiveShoppingSession $session): ?string
    {
        if ($session->status !== LiveShoppingSession::STATUS_FAILED) {
            return null;
        }

        $code = (string) $session->error_code;

        return preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : 'failed';
    }
}
