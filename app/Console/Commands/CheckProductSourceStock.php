<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily check: hit each active product's source_url and detect "out of stock"
 * via simple keyword matching. Updates stock_check_status + last_stock_check_at.
 *
 * Phase 1 = dumb keyword check. Phase 2 will swap in an LLM-driven check.
 */
class CheckProductSourceStock extends Command
{
    protected $signature = 'products:check-source-stock {--id= : Only check a specific product id}';
    protected $description = 'Check source URL availability for store products';

    /**
     * Phrases that indicate the product is unavailable. Case-insensitive.
     */
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

                $this->line("  [{$newStatus}] {$product->slug}");
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
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->retry(2, 1000, throw: false)
            ->get($product->source_url);

        if ($response->status() === 404) {
            $this->saveResult($product, Product::STOCK_OUT_OF_STOCK, "404 Not Found");
            return Product::STOCK_OUT_OF_STOCK;
        }

        if (! $response->successful()) {
            $this->saveResult($product, Product::STOCK_UNKNOWN, "HTTP {$response->status()}");
            return Product::STOCK_UNKNOWN;
        }

        $body = strtolower($response->body());

        foreach ($this->outOfStockPhrases as $phrase) {
            if (str_contains($body, strtolower($phrase))) {
                $this->saveResult($product, Product::STOCK_OUT_OF_STOCK, "Matched: \"{$phrase}\"");
                return Product::STOCK_OUT_OF_STOCK;
            }
        }

        $this->saveResult($product, Product::STOCK_IN_STOCK, "OK · " . strlen($body) . " bytes");
        return Product::STOCK_IN_STOCK;
    }

    private function saveResult(Product $product, string $status, string $note): void
    {
        $product->update([
            'last_stock_check_at' => now(),
            'stock_check_status' => $status,
            'last_stock_check_response' => $note,
        ]);
    }
}
