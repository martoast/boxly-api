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
        // 1. If product has variants OR source URL looks like Shopify, try the JSON endpoint.
        $shopifyJsonUrl = $this->shopifyJsUrl($product->source_url);
        if ($shopifyJsonUrl) {
            $shopifyStatus = $this->checkViaShopifyJson($product, $shopifyJsonUrl);
            if ($shopifyStatus !== null) return $shopifyStatus;
            // If Shopify check failed (e.g. 403), fall through to HTML scrape.
        }

        // 2. Fallback: HTML keyword scrape (no per-variant data — sets product-level only)
        return $this->checkViaHtmlScrape($product);
    }

    /**
     * Convert a Shopify product URL to its .js endpoint:
     *   https://www.youngla.com/products/2093  →  https://www.youngla.com/products/2093.js
     * Returns null if the URL doesn't look like a Shopify product page.
     */
    private function shopifyJsUrl(string $url): ?string
    {
        if (! preg_match('#^(https?://[^/]+/products/[^/?#]+)#', $url, $m)) {
            return null;
        }
        return $m[1] . '.js';
    }

    /**
     * Hit Shopify `.js` endpoint, update each variant's stock, return product-level status.
     * Returns null on transport failure (caller should fall back).
     */
    private function checkViaShopifyJson(Product $product, string $jsUrl): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'application/json',
                ])
                ->retry(2, 1000, throw: false)
                ->get($jsUrl);
        } catch (Throwable $e) {
            return null;
        }

        if (! $response->successful()) return null;

        $json = $response->json();
        if (! is_array($json) || empty($json['variants']) || ! is_array($json['variants'])) {
            return null;
        }

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
            $available = (bool) ($remote['available'] ?? false);
            $newStatus = $available
                ? ProductVariant::STATUS_IN_STOCK
                : ProductVariant::STATUS_OUT_OF_STOCK;

            $local->update([
                'stock_check_status' => $newStatus,
                'last_stock_check_at' => now(),
                'last_stock_check_response' => 'Shopify .js · available=' . ($available ? 'true' : 'false'),
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
                if (! empty($v['available'])) { $anyAvailable = true; break; }
            }
            $product->update([
                'last_stock_check_at' => now(),
                'stock_check_status' => $anyAvailable ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK,
                'last_stock_check_response' => 'Shopify .js · no local variants · ' . count($json['variants']) . ' remote variants · any_available=' . ($anyAvailable ? 'true' : 'false'),
            ]);
            return $product->stock_check_status;
        }

        // With local variants: product is in_stock if any variant is available.
        if (! $matchedAny) {
            // Couldn't match any local variant to remote — keep product status as unknown
            $product->update([
                'last_stock_check_at' => now(),
                'stock_check_status' => Product::STOCK_UNKNOWN,
                'last_stock_check_response' => 'Shopify .js · no variant matches found',
            ]);
            return Product::STOCK_UNKNOWN;
        }

        $product->update([
            'last_stock_check_at' => now(),
            'stock_check_status' => $totalAvailableLocal > 0 ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK,
            'last_stock_check_response' => "Shopify .js · {$totalAvailableLocal}/" . $localVariants->count() . ' variants in stock',
        ]);

        return $product->stock_check_status;
    }

    /**
     * Fallback: scrape the HTML page for out-of-stock keywords.
     * Sets only product-level status — no per-variant data.
     */
    private function checkViaHtmlScrape(Product $product): string
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->retry(2, 1000, throw: false)
            ->get($product->source_url);

        if ($response->status() === 404) {
            $this->saveProductResult($product, Product::STOCK_OUT_OF_STOCK, '404 Not Found');
            return Product::STOCK_OUT_OF_STOCK;
        }

        if (! $response->successful()) {
            $this->saveProductResult($product, Product::STOCK_UNKNOWN, "HTTP {$response->status()}");
            return Product::STOCK_UNKNOWN;
        }

        $body = strtolower($response->body());

        foreach ($this->outOfStockPhrases as $phrase) {
            if (str_contains($body, strtolower($phrase))) {
                $this->saveProductResult($product, Product::STOCK_OUT_OF_STOCK, "HTML matched: \"{$phrase}\"");
                return Product::STOCK_OUT_OF_STOCK;
            }
        }

        $this->saveProductResult($product, Product::STOCK_IN_STOCK, 'HTML OK · ' . strlen($body) . ' bytes');
        return Product::STOCK_IN_STOCK;
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
