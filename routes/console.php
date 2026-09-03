<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Product index upkeep — stages 3 and 4 of app/tasks/product-index.md.
 *
 * ── DISABLED 2026-08-06 — it was burning the SerpAPI plan ──────────────────
 *
 * Measured on the live account with nobody on the site: 45 searches in an hour.
 * The plan is 5,000/month; this alone is ~1,080/day. It ate most of a month's
 * credits in the ~2.5 days since it shipped (dab8334, 2026-08-03).
 *
 * Three compounding faults, all in WarmProductIndex — see api/tasks/todo.md:
 *
 *   1. warmUpstream() runs UNCONDITIONALLY, before the panel is asked whether it
 *      even needs a resolve. Three SerpAPI searches per product are already paid
 *      by the time the panel answers `cached: true`, which the summary then
 *      reports as "already fresh (free)". It was not free. That is why the spend
 *      never showed up in the logs.
 *   2. The warm stage never filters the work list against ProductIndex, so it
 *      re-warms the SAME most-recent purchase-request products every single
 *      hour, forever.
 *   3. The SerpAPI result cache is 30 minutes, so an HOURLY job is guaranteed to
 *      miss on every pass.
 *
 * Re-enable only after 1 and 2 are fixed and the cadence is comfortably longer
 * than the cache TTL. Uncommenting this as-is will exhaust the plan again.
 */
// Schedule::command('boxly:index-warm --limit=25 --max-age=6')
//     ->hourly()
//     ->withoutOverlapping()
//     ->runInBackground();

/*
 * Live Shopping upkeep. Both are no-ops when the feature is disabled.
 * reconcile — releases the one-active-session slot for sessions past the engine's deadline.
 * drain — dispatches processing for durable inbox receipts whose job never ran.
 */
Schedule::command('boxly:live-shopping-reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('boxly:live-shopping-drain')->everyMinute()->withoutOverlapping();
