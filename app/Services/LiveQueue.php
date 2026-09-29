<?php

namespace App\Services;

use App\Jobs\DrainLiveQueueJob;
use App\Models\LiveShoppingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * THE LINE FOR THE LIVE BROWSER (Alex 2026-09-28: "each user has their own separation … at least 3 people at the same
 * time"). The engine runs a few sessions at once and answers engine_busy (retryable) when its slots, a store or a
 * browser profile are taken. A shopper's live search then waits HERE instead of failing: a pending row with no engine
 * session and a queued_at (it keeps the shopper's one active slot and a 10-minute deadline, so the reaper leaves it
 * alone), started in order the moment a slot frees — DrainLiveQueueJob runs after every finished session and every
 * few seconds while anyone waits.
 */
class LiveQueue
{
    public const TTL_SECONDS = 600;

    public static function enabled(): bool
    {
        return Schema::hasColumn('live_shopping_sessions', 'queued_at');
    }

    /** Engine refusals that mean "not now, a slot will free up". */
    public static function isBusy(LiveShoppingEngineException $e): bool
    {
        return in_array($e->getMessage(), ['engine_busy', 'not_accepting', 'worker_ready_timeout', 'create_response_timeout'], true);
    }

    /** Put a pending row in line (it has no engine session). */
    public static function queue(LiveShoppingSession $session): void
    {
        LiveShoppingSession::where('id', $session->id)
            ->where('status', LiveShoppingSession::STATUS_PENDING)
            ->whereNull('engine_session_id')
            ->update(['queued_at' => now(), 'expires_at' => now()->addSeconds(self::TTL_SECONDS), 'updated_at' => now()]);
        DrainLiveQueueJob::dispatch();
    }

    public static function isQueued(LiveShoppingSession $session): bool
    {
        return self::enabled() && $session->queued_at !== null && $session->engine_session_id === null
            && $session->status === LiveShoppingSession::STATUS_PENDING;
    }

    /** 1 = next in line. */
    public static function position(LiveShoppingSession $session): ?int
    {
        if (! self::isQueued($session)) {
            return null;
        }

        return 1 + self::waiting()->where('queued_at', '<', $session->queued_at)->count();
    }

    private static function waiting()
    {
        return LiveShoppingSession::query()
            ->where('status', LiveShoppingSession::STATUS_PENDING)
            ->whereNull('engine_session_id')
            ->whereNotNull('queued_at');
    }

    /** Start what can start, oldest first; true while someone is still waiting. */
    public static function drain(LiveShoppingEngine $engine): bool
    {
        if (! self::enabled()) {
            return false;
        }
        foreach (self::waiting()->orderBy('queued_at')->orderBy('id')->limit(10)->get() as $session) {
            if ($session->expires_at && $session->expires_at->isPast()) {
                self::fail($session, 'queue_timeout');
                continue;
            }
            self::start($session, $engine);
        }

        return self::waiting()->exists();
    }

    /** One try: the engine takes it (running/starting), says busy (stays in line), or refuses (the row fails). */
    public static function start(LiveShoppingSession $session, LiveShoppingEngine $engine): bool
    {
        $storeIds = array_values(array_filter(array_map(fn ($s) => is_array($s) ? ($s['id'] ?? null) : null, (array) $session->stores))) ?: [$session->store_id];
        try {
            $engineSession = $engine->createSession($session->id, $session->conversation_id, $storeIds, $session->objective, $session->kind ?? LiveShoppingSession::KIND_AGENT);
        } catch (LiveShoppingEngineException $e) {
            if (self::isBusy($e)) {
                return false;
            }
            Log::info('queued live session refused by the engine', ['session' => $session->id, 'code' => $e->getMessage()]);
            self::fail($session, $e->getMessage() === 'unknown_store' ? 'store_unsupported' : 'engine_unavailable');

            return false;
        }
        $claimed = LiveShoppingSession::where('id', $session->id)
            ->where('status', LiveShoppingSession::STATUS_PENDING)
            ->whereNull('engine_session_id')
            ->update([
                'engine_session_id' => $engineSession['id'],
                'status'            => $engineSession['status'] === 'running' ? LiveShoppingSession::STATUS_RUNNING : LiveShoppingSession::STATUS_PENDING,
                'expires_at'        => Carbon::parse($engineSession['expires_at']),
                'latest_seq'        => $engineSession['latest_seq'],
                'queued_at'         => null,
                'updated_at'        => now(),
            ]);
        if ($claimed === 0) {
            $engine->cancelSessionQuietly($engineSession['id']);
        }

        return $claimed > 0;
    }

    private static function fail(LiveShoppingSession $session, string $code): void
    {
        LiveShoppingSession::where('id', $session->id)
            ->whereIn('status', LiveShoppingSession::ACTIVE_STATUSES)
            ->whereNull('engine_session_id')
            ->update(['status' => LiveShoppingSession::STATUS_FAILED, 'error_code' => $code, 'active_slot' => null, 'queued_at' => null, 'updated_at' => now()]);
    }
}
