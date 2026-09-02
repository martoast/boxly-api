<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Live Shopping upkeep. Both are no-ops when the feature is disabled.
 *
 * reconcile — releases the one-active-session slot for sessions past the
 *   ENGINE's deadline. Without it a crashed create or a missing terminal webhook
 *   locks a customer out of the feature permanently (P1 has no cancel route).
 *
 * drain — dispatches processing for durable inbox receipts whose job never ran.
 *   This is what makes the webhook's 202 a promise rather than a hope.
 */
Schedule::command('boxly:live-shopping-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('boxly:live-shopping-drain')->everyMinute()->withoutOverlapping();
