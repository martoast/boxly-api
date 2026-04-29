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
        // 1. Try Shopify .js first (returns `available: bool` per variant — cleanest format)
        $jsUrl = $this->shopifyEndpointUrl($product->source_url, '.js');
        if ($jsUrl) {
            $status = $this->checkViaShopifyJson($product, $jsUrl, 'js');
            if ($status !== null) return $status;
        }

        // 2. Try Shopify singular .json (returns `inventory_quantity` per variant — works for Chubbies, etc.)
        $jsonUrl = $this->shopifyEndpointUrl($product->source_url, '.json');
        if ($jsonUrl) {
            $status = $this->checkViaShopifyJson($product, $jsonUrl, 'json');
            if ($status !== null) return $status;
        }

        // 3. Fallback: HTML keyword scrape (product-level only, no per-variant data)
        return $this->checkViaHtmlScrape($product);
    }

    /**
     * Build a Shopify product endpoint URL by appending a suffix to a /products/{handle} URL.
     *   https://store.com/products/abc  →  https://store.com/products/abc.js  (or .json)
     * Returns null if the URL doesn't look like a Shopify product page.
     */
    private function shopifyEndpointUrl(string $url, string $suffix): ?string
    {
        if (! preg_match('#^(https?://[^/]+/products/[^/?#]+)#', $url, $m)) {
            return null;
        }
        return $m[1] . $suffix;
    }

    /**
     * Hit Shopify `.js` or `.json` endpoint, update each variant's stock, return product-level status.
     * Returns null on transport failure (caller should fall back).
     *
     * @param string $format 'js' or 'json' — controls how we read availability
     */
    private function checkViaShopifyJson(Product $product, string $url, string $format): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'application/json',
                ])
                ->retry(2, 1000, throw: false)
                ->get($url);
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

        // Normalize availability per variant. .js gives `available` bool; .json gives `inventory_quantity`.
        $remoteVariants = array_map(function ($v) use ($format) {
            $v['_available'] = $format === 'json'
                ? ((int) ($v['inventory_quantity'] ?? 0)) > 0
                : (bool) ($v['available'] ?? false);
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
