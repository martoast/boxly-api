<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Boxly Store: daily check of source URLs for stock availability.
Schedule::command('products:check-source-stock')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();
