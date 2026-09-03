<?php

namespace App\Jobs;

use App\Http\Controllers\ProductExtractController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Prime the Google-Shopping cache for a query whose SerpAPI pass returned nothing,
 * using the slow-but-reliable ScraperAPI structured endpoint (~20-40s). Runs on the
 * queue so that 40s NEVER sits on the user's search request — the next identical
 * search hits the warmed cache instead of paying the wall (or showing nothing).
 *
 * Dispatched from ProductExtractController::search(); the prod supervisor worker
 * (queue:work) processes it. No-op when there's no ScraperAPI key (e.g. local).
 */
class PrimeShoppingCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(public string $query, public int $start = 0) {}

    public function handle(): void
    {
        $location = config('services.serpapi.location');
        $cacheKey = ProductExtractController::shopCacheKey($this->query, $location, $this->start);

        // A real SerpAPI result may have landed since we queued — don't clobber it.
        if (Cache::get($cacheKey) !== null) {
            return;
        }

        $products = ProductExtractController::shoppingViaScraperapi($this->query);
        if (is_array($products) && $products !== []) {
            Cache::put($cacheKey, $products, now()->addMinutes(30));
        }
    }
}
