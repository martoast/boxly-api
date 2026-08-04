<?php

namespace App\Console\Commands;

use App\Models\ProductIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fill and refresh the product index ahead of the shopper.
 *
 * Stages 3 and 4 of app/tasks/product-index.md, and they are the same operation
 * pointed at two different work lists:
 *
 *   WARM (3)    — products our customers already bought, from purchase request
 *                 history. Phia indexes 250 million products because it does not
 *                 know what you will look at. We do: a few thousand items that
 *                 Mexican customers actually order from US stores, which is a
 *                 far better list than a far bigger one.
 *
 *   REFRESH (4) — index rows whose prices have aged out, most-requested first.
 *                 THIS is where "instant" comes from. A shopper reading a row we
 *                 resolved an hour ago waits 1 second; the same shopper
 *                 resolving it themselves waits 17. The work does not get
 *                 cheaper, it just stops happening while they watch.
 *
 * Resolution goes through the Nuxt panel endpoint rather than being
 * reimplemented here, so there is exactly ONE definition of how a product is
 * resolved and keyed. This command only decides WHAT to resolve and WHEN.
 *
 * ── This spends money ──────────────────────────────────────────────────────
 *
 * Every miss costs a SerpAPI search, a vision pass and a verification chain.
 * Hard-capped per run, paced between calls, and the spend is logged. Raising
 * --limit raises the bill; that is why the default is small and why the summary
 * prints how many calls actually did work.
 *
 * Config:
 *   SHOPPER_APP_URL        the Nuxt app (default https://boxly.mx)
 *   PRODUCT_INDEX_SECRET   shared with Nuxt; without it, nothing runs
 */
class WarmProductIndex extends Command
{
    protected $signature = 'boxly:index-warm
        {--mode=both : warm | refresh | both}
        {--limit=25 : hard cap on products resolved this run}
        {--max-age=6 : refresh rows whose prices are older than this many hours}
        {--sleep=2 : seconds between calls, to be a good citizen to SerpAPI}
        {--dry : list what would be resolved, spend nothing}';

    protected $description = 'Warm and refresh the Shopper product index';

    public function handle(): int
    {
        $secret = (string) env('PRODUCT_INDEX_SECRET', '');
        if ($secret === '') {
            $this->error('PRODUCT_INDEX_SECRET is not set — the index is off, nothing to warm.');

            return self::FAILURE;
        }

        $app = rtrim((string) env('SHOPPER_APP_URL', 'https://boxly.mx'), '/');
        $mode = (string) $this->option('mode');
        $limit = max(1, (int) $this->option('limit'));
        $maxAge = max(1, (int) $this->option('max-age'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dry = (bool) $this->option('dry');

        $work = [];

        // ── Stage 4 first, deliberately ────────────────────────────────────
        // A stale row is actively serving prices to shoppers RIGHT NOW; an
        // unwarmed product is merely slow the first time. Given one budget,
        // correctness of what we already show outranks breadth of what we might.
        if ($mode === 'refresh' || $mode === 'both') {
            $stale = ProductIndex::where('resolved_at', '<', now()->subHours($maxAge))
                ->orderByDesc('hits')          // most-requested first
                ->orderBy('resolved_at')       // then oldest
                ->limit($limit)
                ->get();

            foreach ($stale as $row) {
                // No source_url means the row predates that column and there is
                // no honest way to re-resolve it — skip rather than guess a URL
                // and overwrite good prices with a wrong product's.
                if (! $row->source_url) {
                    continue;
                }
                $work[] = [
                    'why' => 'refresh',
                    'url' => $row->source_url,
                    'title' => $row->title,
                    'brand' => $row->brand,
                    'variant' => $row->variant,
                    'store' => $row->store,
                    'key' => $row->canonical_key,
                ];
            }
        }

        // ── Stage 3 ────────────────────────────────────────────────────────
        if ($mode === 'warm' || $mode === 'both') {
            $room = $limit - count($work);
            if ($room > 0) {
                // Most recent first: what customers ordered last week is what
                // they are most likely to look at next. DISTINCT on the URL so a
                // product ordered nine times is resolved once.
                $items = DB::table('purchase_request_items')
                    ->select('product_url', 'product_name', DB::raw('MAX(id) as newest'))
                    ->whereNotNull('product_url')
                    ->where('product_url', 'like', 'http%')
                    ->groupBy('product_url', 'product_name')
                    ->orderByDesc('newest')
                    ->limit($room * 4) // over-fetch; many will already be indexed
                    ->get();

                foreach ($items as $it) {
                    if (count($work) >= $limit) {
                        break;
                    }
                    $work[] = [
                        'why' => 'warm',
                        'url' => $it->product_url,
                        'title' => $it->product_name,
                        'brand' => null,
                        'variant' => null,
                        'store' => null,
                        'key' => null,
                    ];
                }
            }
        }

        if (! $work) {
            $this->info('Nothing to do — index is warm and fresh.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d product(s) queued (%s)%s', count($work), $mode, $dry ? ' — DRY RUN' : ''));

        $resolved = 0;
        $servedFromIndex = 0;
        $failed = 0;

        foreach ($work as $i => $w) {
            if (! $w['url'] || ! $w['title']) {
                continue;
            }

            if ($dry) {
                $this->line(sprintf('  [%s] %s', $w['why'], mb_substr((string) $w['title'], 0, 70)));
                continue;
            }

            try {
                $res = Http::timeout(90)
                    ->withHeaders(['X-Boxly-Index-Secret' => $secret])
                    ->post($app.'/api/shopper/panel', array_filter([
                        'url' => $w['url'],
                        'title' => $w['title'],
                        'brand' => $w['brand'],
                        'variant' => $w['variant'],
                        'store' => $w['store'],
                        // Refresh must IGNORE what we remembered, or it reads
                        // back the very row it was sent to replace.
                        'refresh' => $w['why'] === 'refresh',
                    ], fn ($v) => $v !== null));

                if (! $res->successful()) {
                    $failed++;
                    $this->warn(sprintf('  ✗ %s (HTTP %d)', mb_substr((string) $w['title'], 0, 50), $res->status()));
                } else {
                    // `cached` true means the panel answered from memory or the
                    // index — no SerpAPI spend. Worth counting: it is the
                    // difference between this run costing money and costing
                    // nothing.
                    if ($res->json('cached') === true) {
                        $servedFromIndex++;
                    } else {
                        $resolved++;
                    }
                    $this->line(sprintf('  ✓ [%s] %s', $w['why'], mb_substr((string) $w['title'], 0, 60)));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn(sprintf('  ✗ %s (%s)', mb_substr((string) $w['title'], 0, 40), $e->getMessage()));
            }

            if ($sleep && $i < count($work) - 1) {
                sleep($sleep);
            }
        }

        if (! $dry) {
            $summary = sprintf(
                'index-warm: %d resolved (paid), %d already fresh (free), %d failed',
                $resolved, $servedFromIndex, $failed
            );
            $this->info($summary);
            // Logged as well as printed: this runs unattended on a schedule, and
            // a silent cost is how a cron quietly becomes a bill.
            Log::info('[product-index] '.$summary);
        }

        return self::SUCCESS;
    }
}
