<?php

namespace App\Console\Commands;

use App\Models\ShoppingTrip;
use Illuminate\Console\Command;

/**
 * Seed the shopping-trip availability calendar.
 *
 * Defaults: every Saturday + Sunday for the next 6 months at Las Americas
 * Premium Outlets, 09:00–20:00, open status. Idempotent — re-running
 * updates existing rows for those dates instead of duplicating them.
 *
 * Usage:
 *   php artisan shopping-trips:seed
 *   php artisan shopping-trips:seed --months=3 --start=10:00 --end=19:00
 *   php artisan shopping-trips:seed --location="Otay Ranch Town Center"
 */
class SeedShoppingTrips extends Command
{
    protected $signature = 'shopping-trips:seed
                            {--months=6 : How many months ahead to seed}
                            {--start=09:00 : start_time (HH:MM)}
                            {--end=20:00 : end_time (HH:MM)}
                            {--location=Las Americas Premium Outlets : Trip location}';

    protected $description = 'Open weekend trips (Sat+Sun) for the next N months';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $start = $this->option('start');
        $end = $this->option('end');
        $location = $this->option('location');

        $d = now()->startOfDay();
        $stop = $d->copy()->addMonths($months);

        $n = 0;
        while ($d->lte($stop)) {
            if ($d->isWeekend()) {
                ShoppingTrip::updateOrCreate(
                    ['trip_date' => $d->toDateString(), 'location' => $location],
                    ['status' => ShoppingTrip::STATUS_OPEN, 'start_time' => $start, 'end_time' => $end],
                );
                $n++;
            }
            $d = $d->addDay();
        }

        $this->info("{$n} Sat/Sun trips ensured for '{$location}' ({$start}–{$end}, next {$months} months)");

        return self::SUCCESS;
    }
}
