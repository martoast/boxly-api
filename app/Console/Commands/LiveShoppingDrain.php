<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\LiveShoppingWebhookReceipt;
use App\Services\LiveShoppingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Drain the durable webhook inbox.
 *
 * THIS IS WHAT MAKES THE 202 TRUTHFUL. The webhook commits a receipt and then
 * dispatches a job, but the dispatch is only a latency optimisation: if the
 * process died between the commit and the dispatch, or the queue was down, the
 * work would otherwise be lost even though we promised the engine it would
 * happen. With this sweep, the only thing that must succeed for the promise to
 * hold is the commit the response already waited on.
 *
 * A double dispatch is harmless by construction: the job locks the receipt and
 * transitions received -> processed, so whichever of the fast path and this
 * command arrives second finds a non-'received' row and returns.
 */
class LiveShoppingDrain extends Command
{
    protected $signature = 'boxly:live-shopping-drain {--limit=100}';

    protected $description = 'Dispatch processing for live shopping webhook receipts still awaiting work';

    public function handle(LiveShoppingEngine $engine): int
    {
        if (! $engine->enabled() || ! Schema::hasTable('live_shopping_webhook_receipts')) {
            return self::SUCCESS;   // never sweep rows for a feature that is off
        }

        // The grace keeps this from racing a job already in flight on the fast
        // path; it is not a correctness requirement, just less duplicate work.
        $grace = max(5, min(600, (int) config('services.live_shopping_engine.drain_grace', 30)));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $receipts = LiveShoppingWebhookReceipt::query()
            ->drainable($grace)
            ->orderBy('id')
            // Bounded batch so a backlog drains steadily instead of one tick
            // trying to do everything.
            ->limit($limit)
            ->get(['id']);

        foreach ($receipts as $receipt) {
            ProcessLiveShoppingResultJob::dispatch($receipt->id)->onConnection('database');
        }

        if ($receipts->isNotEmpty()) {
            $this->info("Dispatched {$receipts->count()} live shopping receipt(s).");
        }

        return self::SUCCESS;
    }
}
