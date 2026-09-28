<?php

namespace App\Jobs;

use App\Models\CartItem;
use App\Models\PurchaseRequestItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * A cart line or order item without a photo (a pasted link, a web store, a product the agent found with a store's
 * search — live Lab 2026-09-28: Carhartt and Owala items had no image on the order page, the email or the admin
 * view) gets the product page's own photo from the catalog service's LIVE product read — the same read the
 * size/colour picker uses: our own browser, so it gets past store bot walls that refuse a plain server request, and
 * it returns the product photo (a page's share image is sometimes the brand's logo). Never throws; no photo found
 * leaves the item as it was.
 */
class FillItemImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public string $kind, public int $id)
    {
    }

    public function handle(): void
    {
        $item = $this->kind === 'cart' ? CartItem::find($this->id) : PurchaseRequestItem::find($this->id);
        if (! $item) {
            return;
        }
        $has = $this->kind === 'cart' ? filled($item->image_url) : (filled($item->image_url) || filled($item->product_image_url));
        $url = (string) $item->product_url;
        // A search item's url is the store's site until the agent finds the product page.
        if ($has || $url === '' || str_contains($url, 'boxly_find=')) {
            return;
        }
        $image = self::productImage($url);
        if ($image === null) {
            return;
        }
        $item->forceFill($this->kind === 'cart' ? ['image_url' => $image] : ['product_image_url' => $image])->save();
    }

    /** The product's photo from the catalog service's live read (https only), or null. */
    public static function productImage(string $url): ?string
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return null;
        }
        try {
            $res = Http::timeout(60)->acceptJson()->post("{$base}/catalog/product-variants", ['url' => $url]);
        } catch (\Throwable) {
            return null;
        }
        if (! $res->ok()) {
            return null;
        }
        $product = (array) ($res->json('product') ?? []);
        foreach (array_merge([$product['image'] ?? null], (array) ($product['images'] ?? [])) as $candidate) {
            if (is_string($candidate) && strlen($candidate) <= 2048) {
                $candidate = preg_replace('/^http:\/\//i', 'https://', trim($candidate));
                $parts = parse_url($candidate);
                if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
