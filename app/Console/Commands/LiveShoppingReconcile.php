<?php

namespace App\Console\Commands;

use App\Models\LiveShoppingSession;
use App\Services\LiveShoppingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release sessions that outlived the ENGINE's deadline.
 *
 * Why this exists: active_slot is claimed at INSERT and released only by a
 * terminal transition, and P1 has no cancel route. Without reconciliation, a
 * process that dies mid-create, or an engine that never delivers a terminal
 * webhook (user closed the tab, engine crashed), would lock that customer out of
 * the feature permanently, with DB surgery as the only remedy.
 *
 * The deadline is the engine's, never ours. A blind created_at timeout would
 * kill sessions the engine still considers alive and drift from whatever it
 * actually enforces.
 */
class LiveShoppingReconcile extends Command
{
    protected $signature = 'boxly:live-shopping-reconcile';

    protected $description = 'Fail live shopping sessions past the engine-issued deadline and release their slot';

    public function handle(LiveShoppingEngine $engine): int
    {
        if (! $engine->enabled() || ! Schema::hasTable('live_shopping_sessions')) {
            return self::SUCCESS;   // never sweep rows for a feature that is off
        }

        $grace = max(0, min(3600, (int) config('services.live_shopping_engine.expiry_grace', 60)));
        $cutoff = now()->subSeconds($grace);

        // Two distinct populations, deliberately:
        //  1. rows WITH a deadline  -> judged only by that deadline.
        //  2. rows with NO deadline -> the crash-before-engine-response case:
        //     INSERT committed, the create call never returned. That is a bound
        //     on an unanswered HTTP call, not an age timeout on a live session.
        $callTimeout = max(1, min(30, (int) config('services.live_shopping_engine.timeout', 8)));
        $noDeadlineCutoff = now()->subSeconds($callTimeout + $grace);

        $released = 0;

        LiveShoppingSession::query()
            ->active()
            ->where(function ($q) use ($cutoff, $noDeadlineCutoff) {
                $q->where(fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', $cutoff))
                    ->orWhere(fn ($q) => $q->whereNull('expires_at')->where('created_at', '<', $noDeadlineCutoff));
            })
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$released, $cutoff, $noDeadlineCutoff) {
                foreach ($sessions as $session) {
                    // One transaction per row: the status change and the slot
                    // release must never half-apply.
                    DB::transaction(function () use ($session, &$released, $cutoff, $noDeadlineCutoff) {
                        $fresh = LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();

                        // Re-evaluate the EXACT predicate under the lock, not
                        // just isTerminal(). Between the chunk read and this
                        // lock the row may have gained a deadline (the create
                        // call returned) or had its slot released. Acting on a
                        // row that no longer matches is how a live session gets
                        // killed out from under a customer.
                        if (! $fresh || $fresh->isTerminal() || $fresh->active_slot === null) {
                            return;
                        }
                        $stillExpired = $fresh->expires_at !== null
                            ? $fresh->expires_at->lt($cutoff)
                            : $fresh->created_at->lt($noDeadlineCutoff);
                        if (! $stillExpired) {
                            return;   // it acquired a live deadline while we looked away
                        }

                        $fresh->forceFill([
                            'status'      => LiveShoppingSession::STATUS_FAILED,
                            'error_code'  => 'expired',
                            'active_slot' => null,
                        ])->save();

                        $released++;
                    });
                }
            });

        if ($released > 0) {
            $this->info("Expired {$released} live shopping session(s).");
        }

        return self::SUCCESS;
    }
}
