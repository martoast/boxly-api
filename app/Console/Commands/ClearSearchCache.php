<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clears the AI-search server-side caches (product search results + product-page
 * scrapes) so a fresh search/product flow can be tested end-to-end. Leaves the
 * rest of the app cache intact unless --all is passed.
 */
class ClearSearchCache extends Command
{
    protected $signature = 'boxly:clear-search-cache {--all : Flush the ENTIRE cache store instead of just AI-search entries}';

    protected $description = 'Clear the AI-search cache (product search + product-page results) for fresh testing';

    public function handle(): int
    {
        if ($this->option('all')) {
            Cache::flush();
            $this->info('✓ Flushed the entire cache store.');

            return self::SUCCESS;
        }

        $store = config('cache.default');

        // The DB cache driver lets us delete just our prefixed keys
        // (serp_shop:* = searches, pdp:* = product pages).
        if ($store === 'database') {
            $table = config('cache.stores.database.table', 'cache');
            $deleted = DB::table($table)
                ->where('key', 'like', '%serp_shop:%')
                ->orWhere('key', 'like', '%pdp:%')
                ->delete();
            $this->info("✓ Cleared {$deleted} AI-search cache entrie(s) — search results + product pages.");
            $this->line('  A guest can now run a fully fresh search/product flow.');

            return self::SUCCESS;
        }

        // Other drivers (redis/memcached) can't be prefix-scanned cheaply here —
        // flush the whole store as a safe fallback.
        Cache::flush();
        $this->warn("Cache driver '{$store}' doesn't support targeted clears — flushed the entire cache store instead.");

        return self::SUCCESS;
    }
}
