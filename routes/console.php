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
 * Hourly, small, and OFF unless PRODUCT_INDEX_SECRET is set (the command exits
 * immediately without it). withoutOverlapping because a run can take minutes
 * and two of them racing would double the SerpAPI spend for nothing.
 *
 * Refreshes stale rows before warming new ones — a stale row is serving prices
 * to shoppers right now, an unwarmed product is merely slow the first time.
 */
Schedule::command('boxly:index-warm --limit=25 --max-age=6')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
