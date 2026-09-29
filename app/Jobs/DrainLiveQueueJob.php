<?php

namespace App\Jobs;

use App\Services\LiveQueue;
use App\Services\LiveShoppingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Start the shoppers waiting for the live browser (LiveQueue), in order. Dispatched when someone joins the line and
 * after every finished session; while anyone still waits it runs again a few seconds later. Unique until it starts: one drain waits in the queue at a time, and a running drain can queue the next.
 */
class DrainLiveQueueJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $uniqueFor = 10;

    public function handle(LiveShoppingEngine $engine): void
    {
        if (LiveQueue::drain($engine)) {
            self::dispatch()->delay(now()->addSeconds(3));
        }
    }
}
