<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily check: hit each active product's source_url and detect stock availability.
 *
 * Strategy:
 *  1. If the source URL looks like Shopify (most common case), hit `{url}.js`
 *     and parse the variants array — each has `available: bool`. We update each
 *     variant's stock_check_status and roll up to the product.
 *  2. Otherwise fall back to keyword scrape on the HTML page.
 */
class CheckProductSourceStock extends Command
{
    protected $signature = 'products:check-source-stock {--id= : Only check a specific product id}';
    protected $description = 'Check source URL availability for store products';

    private array $outOfStockPhrases = [
        'out of stock',
        'sold out',
        'no disponible',
        'agotado',
        'currently unavailable',
        'temporarily unavailable',
        'not available',
        'no longer available',
    ];

    private string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function handle(): int
    {
        if (! config('services.scraperapi.key')) {
            $this->error('SCRAPERAPI_KEY is not configured. Set it in env and retry.');
            return self::FAILURE;
        }

        $query = Product::active()->whereNotNull('source_url');

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('No products to check.');
            return self::SUCCESS;
        }

        $this->info("Checking {$products->count()} products...");

        $changed = 0;
        foreach ($products as $product) {
            try {
                $previousStatus = $product->stock_check_status;
                $newStatus = $this->checkProduct($product);

                if ($previousStatus !== $newStatus) {
                    $changed++;
                    Log::info('Product stock status changed', [
                        'product_id' => $product->id,
                        'slug' => $product->slug,
                        'from' => $previousStatus,
                        'to' => $newStatus,
                    ]);
                }

                $variantCount = $product->variants()->count();
                $variantSummary = $variantCount > 0
                    ? " ({$product->variants()->where('stock_check_status', '!=', ProductVariant::STATUS_OUT_OF_STOCK)->count()}/{$variantCount} variants in stock)"
                    : '';
                $this->line("  [{$newStatus}] {$product->slug}{$variantSummary}");
            } catch (Throwable $e) {
                Log::warning('Stock check failed', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                $product->update([
                    'last_stock_check_at' => now(),
                    'stock_check_status' => Product::STOCK_UNKNOWN,
                    'last_stock_check_response' => 'ERROR: ' . substr($e->getMessage(), 0, 400),
                ]);
                $this->line("  [error] {$product->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Done. {$changed} status changes.");
        return self::SUCCESS;
    }

    private function checkProduct(Product $product): string
    {
        // Pipeline (all calls go via ScraperAPI):
        //   1. Shopify .json   → rich per-variant data (most Shopify stores)
        //   2. Shopify .js     → fallback when .json strips inventory (YoungLA)
        //   3. HTML JSON-LD    → universal for non-Shopify stores (BBW, etc.)
        //   4. HTML keywords   → last resort for non-Shopify with no JSON-LD

        $jsonUrl = $this->shopifyEndpointUrl($product->source_url, '.json');
        if ($jsonUrl) {
            $status = $this->checkViaShopifyJson($product, $jsonUrl, 'json');
            if ($status !== null) return $status;
        }

        $jsUrl = $this->shopifyEndpointUrl($product->source_url, '.js');
        if ($jsUrl) {
            $status = $this->checkViaShopifyJson($product, $jsUrl, 'js');
            if ($status !== null) return $status;
        }

        // Fetch HTML once and try both JSON-LD (structured) then keyword scraping.
        return $this->checkViaHtmlScrape($product);
    }

    /**
     * Build a Shopify product endpoint URL by appending a suffix to a /products/{handle} URL.
     *   https://store.com/products/abc  →  https://store.com/products/abc.js  (or .json)
     * Returns null if the URL doesn't look like a Shopify product page.
     */
    private function shopifyEndpointUrl(string $url, string $suffix): ?string
    {
        // Use ~ delimiter so we can safely include / and # inside the char class
        if (! preg_match('~^(https?://[^/]+/products/[^/?#]+)~', $url, $m)) {
            return null;
        }
        return $m[1] . $suffix;
    }

    /**
     * Hit a Shopify `.js` or `.json` endpoint via ScraperAPI, update each variant's
     * stock, return product-level status. Returns null on transport failure
     * (caller falls back to the next endpoint or HTML scrape).
     *
     * @param string $format 'js' or 'json' — controls how we read availability
     */
    private function checkViaShopifyJson(Product $product, string $url, string $format): ?string
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'application/json',
                ])
                // Retry only on transient errors (5xx, timeouts) — never on 4xx
                // since 404/410 are definitive answers and retrying just burns credits.
                ->retry(2, 1000, function ($exception, $request) {
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) return true;
                    return false;
                }, throw: false)
                ->get($this->scraperApiWrap($url));
        } catch (Throwable $e) {
            return null;
        }

        if (! $response->successful()) return null;

        $json = $response->json();

        // .js returns the product at root; .json nests it under "product"
        $root = $format === 'json' ? ($json['product'] ?? null) : $json;

        if (! is_array($root) || empty($root['variants']) || ! is_array($root['variants'])) {
            return null;
        }

        // Some stores (YoungLA, etc.) strip `inventory_quantity` AND `available` from
        // their public .json — leaving us no way to tell stock status. If neither field
        // is populated on ANY variant, return null so the caller falls back to .js.
        $hasUsableStock = false;
        foreach ($root['variants'] as $v) {
            if (array_key_exists('available', $v) && $v['available'] !== null) { $hasUsableStock = true; break; }
            if (array_key_exists('inventory_quantity', $v) && $v['inventory_quantity'] !== null) { $hasUsableStock = true; break; }
        }
        if (! $hasUsableStock) return null;

        // Normalize availability per variant. .js gives `available` bool; .json gives `inventory_quantity`.
        $remoteVariants = array_map(function ($v) use ($format) {
            if ($format === 'json' && array_key_exists('inventory_quantity', $v) && $v['inventory_quantity'] !== null) {
                $v['_available'] = ((int) $v['inventory_quantity']) > 0;
            } else {
                $v['_available'] = (bool) ($v['available'] ?? false);
            }
            return $v;
        }, $root['variants']);

        // Replace the array used downstream
        $json = ['variants' => $remoteVariants];

        // Index Shopify variants by id and by option1/option2
        $byId = [];
        foreach ($json['variants'] as $v) {
            if (isset($v['id'])) $byId[(string) $v['id']] = $v;
        }

        // For every Boxly variant, find its match and update stock.
        $localVariants = $product->variants()->get();
        $totalAvailableLocal = 0;
        $matchedAny = false;

        foreach ($localVariants as $local) {
            $remote = null;

            if ($local->shopify_variant_id && isset($byId[$local->shopify_variant_id])) {
                $remote = $byId[$local->shopify_variant_id];
            } else {
                // Fallback: match by option1/option2 against local size/color
                foreach ($json['variants'] as $v) {
                    $opts = [
                        strtolower($v['option1'] ?? ''),
                        strtolower($v['option2'] ?? ''),
                        strtolower($v['option3'] ?? ''),
                    ];
                    $size  = strtolower((string) $local->size);
                    $color = strtolower((string) $local->color);
                    $sizeOk  = ! $size  || in_array($size, $opts, true);
                    $colorOk = ! $color || in_array($color, $opts, true);
                    if ($sizeOk && $colorOk) {
                        $remote = $v;
                        break;
                    }
                }
            }

            if ($remote === null) {
                $local->update([
                    'stock_check_status' => ProductVariant::STATUS_UNKNOWN,
                    'last_stock_check_at' => now(),
                    'last_stock_check_response' => 'No matching variant in Shopify response',
                ]);
                continue;
            }

            $matchedAny = true;
            $available = (bool) ($remote['_available'] ?? false);
            $newStatus = $available
                ? ProductVariant::STATUS_IN_STOCK
                : ProductVariant::STATUS_OUT_OF_STOCK;

            $local->update([
                'stock_check_status' => $newStatus,
                'last_stock_check_at' => now(),
                'last_stock_check_response' => "Shopify {$format} · available=" . ($available ? 'true' : 'false'),
                // Backfill shopify_variant_id if we matched via options
                'shopify_variant_id' => $local->shopify_variant_id ?: ($remote['id'] ?? null),
            ]);

            if ($available) $totalAvailableLocal++;
        }

        // If product has no local variants but the Shopify response shows variants exist,
        // roll up to product-level status based on whether ANY are available.
        if ($localVariants->isEmpty()) {
            $anyAvailable = false;
            foreach ($json['variants'] as $v) {
                if (! empty($v['_available'])) { $anyAvailable = true; break; }
            }
            $product->update([
                'last_stock_check_at' => now(),
                'stock_check_status' => $anyAvailable ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK,
                'last_stock_check_response' => "Shopify {$format} · no local variants · " . count($json['variants']) . ' remote variants · any_available=' . ($anyAvailable ? 'true' : 'false'),
            ]);
            return $product->stock_check_status;
        }

        // With local variants: product is in_stock if any variant is available.
        if (! $matchedAny) {
            $product->update([
                'last_stock_check_at' => now(),
                'stock_check_status' => Product::STOCK_UNKNOWN,
                'last_stock_check_response' => "Shopify {$format} · no variant matches found",
            ]);
            return Product::STOCK_UNKNOWN;
        }

        $product->update([
            'last_stock_check_at' => now(),
            'stock_check_status' => $totalAvailableLocal > 0 ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK,
            'last_stock_check_response' => "Shopify {$format} · {$totalAvailableLocal}/" . $localVariants->count() . ' variants in stock',
        ]);

        return $product->stock_check_status;
    }

    /**
     * Fetch the HTML page once and try, in order:
     *   1. JSON-LD Product schema (universal — Demandware, Magento, custom stores all use it)
     *   2. Out-of-stock keyword scan (last-resort heuristic)
     */
    private function checkViaHtmlScrape(Product $product): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->retry(2, 1000, function ($exception, $request) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) return true;
                return false;
            }, throw: false)
            ->get($this->scraperApiWrap($product->source_url));

        if ($response->status() === 404) {
            $this->saveProductResult($product, Product::STOCK_OUT_OF_STOCK, '404 Not Found');
            return Product::STOCK_OUT_OF_STOCK;
        }

        if (! $response->successful()) {
            $this->saveProductResult($product, Product::STOCK_UNKNOWN, "HTTP {$response->status()}");
            return Product::STOCK_UNKNOWN;
        }

        $body = $response->body();

        // Step 1: try JSON-LD Product schema. This is what Google requires retailers
        // to publish, so virtually every major non-Shopify store has it.
        $jsonLdStatus = $this->checkViaJsonLd($body);
        if ($jsonLdStatus !== null) {
            $this->saveProductResult(
                $product,
                $jsonLdStatus,
                "JSON-LD · " . ($jsonLdStatus === Product::STOCK_IN_STOCK ? 'InStock' : 'OutOfStock')
            );
            return $jsonLdStatus;
        }

        // Step 2: heuristic keyword scan
        $lower = strtolower($body);
        foreach ($this->outOfStockPhrases as $phrase) {
            if (str_contains($lower, strtolower($phrase))) {
                $this->saveProductResult($product, Product::STOCK_OUT_OF_STOCK, "HTML matched: \"{$phrase}\"");
                return Product::STOCK_OUT_OF_STOCK;
            }
        }

        $this->saveProductResult($product, Product::STOCK_IN_STOCK, 'HTML OK · ' . strlen($body) . ' bytes');
        return Product::STOCK_IN_STOCK;
    }

    /**
     * Look for a JSON-LD Product schema in the HTML and read its `offers.availability`.
     *
     * Returns Product::STOCK_IN_STOCK / STOCK_OUT_OF_STOCK if a Product schema is
     * found and we can determine availability. Returns null if no schema found —
     * caller should fall back to keyword scraping.
     */
    private function checkViaJsonLd(string $html): ?string
    {
        // Find every <script type="application/ld+json">...</script> block
        if (! preg_match_all('~<script[^>]*application/ld\+json[^>]*>(.*?)</script>~is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (! $decoded) continue;

            // Schema can be an array, a Graph, or a single object
            $items = isset($decoded['@graph']) ? $decoded['@graph']
                : (isset($decoded[0]) ? $decoded : [$decoded]);

            foreach ($items as $item) {
                if (! is_array($item)) continue;
                $type = $item['@type'] ?? null;
                $isProduct = is_string($type) ? $type === 'Product'
                    : (is_array($type) && in_array('Product', $type, true));
                if (! $isProduct) continue;

                $offers = $item['offers'] ?? null;
                if (! $offers) continue;

                // offers can be a single object or an array of variant offers
                $offerList = isset($offers[0]) ? $offers : [$offers];
                $anyAvailable = false;
                $anyOffer = false;

                foreach ($offerList as $offer) {
                    if (! is_array($offer)) continue;
                    $anyOffer = true;
                    $availability = strtolower((string) ($offer['availability'] ?? ''));
                    // schema.org/InStock = available
                    if (str_contains($availability, 'instock')
                        || str_contains($availability, 'limitedavailability')
                        || str_contains($availability, 'onlineonly')
                        || str_contains($availability, 'preorder')) {
                        $anyAvailable = true;
                    }
                }

                if (! $anyOffer) continue;
                return $anyAvailable ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK;
            }
        }

        return null;
    }

    /**
     * Wrap a target URL so it goes through ScraperAPI (which handles Cloudflare,
     * TLS fingerprinting, and JS challenges so we get the real content back).
     */
    private function scraperApiWrap(string $targetUrl): string
    {
        $key = config('services.scraperapi.key');
        if (! $key) {
            throw new \RuntimeException('SCRAPERAPI_KEY not configured');
        }
        return 'https://api.scraperapi.com/?' . http_build_query([
            'api_key' => $key,
            'url' => $targetUrl,
        ]);
    }

    private function saveProductResult(Product $product, string $status, string $note): void
    {
        $product->update([
            'last_stock_check_at' => now(),
            'stock_check_status' => $status,
            'last_stock_check_response' => $note,
        ]);
    }
}
