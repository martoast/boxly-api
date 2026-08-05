<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

/**
 * Boxly Protection — the live Stripe price for the optional per-box add-on.
 *
 * The amount is never hardcoded. Stripe is the single source of truth for what
 * protection costs today, exactly as it is for box prices; raising the price in
 * Stripe has to be enough to change what the next order is billed.
 *
 * Cached briefly so consolidating a 6-box order doesn't make 6 identical calls
 * to Stripe. Short TTL because a price change should take effect within
 * minutes, not on the next deploy.
 */
class ProtectionProduct
{
    private const CACHE_KEY = 'boxly.protection.price';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * The active price for Boxly Protection, or null if Stripe can't answer.
     *
     * @return array{price_id: string, product_id: string, amount: float, currency: string}|null
     */
    public static function price(): ?array
    {
        $productId = config('services.boxly_protection.product_id');

        if (! $productId) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($productId) {
            try {
                $stripe = Cashier::stripe();
                $product = $stripe->products->retrieve($productId);

                // Prefer the product's default price; fall back to its first
                // active one so a cleared default doesn't take the feature down.
                $price = $product->default_price
                    ? $stripe->prices->retrieve($product->default_price)
                    : ($stripe->prices->all(['product' => $productId, 'active' => true, 'limit' => 1])->data[0] ?? null);

                if (! $price || ! $price->active) {
                    Log::warning('Boxly Protection has no active Stripe price', ['product' => $productId]);
                    return null;
                }

                return [
                    'price_id'   => $price->id,
                    'product_id' => $productId,
                    'amount'     => $price->unit_amount / 100,
                    'currency'   => strtolower($price->currency),
                    'name'       => $product->name,
                ];
            } catch (\Exception $e) {
                Log::error('Could not read the Boxly Protection price from Stripe', [
                    'product' => $productId,
                    'error'   => $e->getMessage(),
                ]);
                return null;
            }
        });
    }
}
