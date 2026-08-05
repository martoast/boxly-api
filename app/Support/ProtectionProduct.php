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

                // The product can carry prices in several currencies (it
                // currently has both MXN and USD). Boxes are billed in the
                // Cashier currency, and a line in another currency cannot go on
                // that invoice — so only prices in the billing currency count.
                // Picking by default_price alone would put USD on an MXN
                // invoice the moment someone changes the default in Stripe.
                $currency = strtolower(config('cashier.currency', 'mxn'));

                $candidates = collect($stripe->prices->all([
                    'product' => $productId,
                    'active'  => true,
                    'limit'   => 100,
                ])->data)->filter(fn ($p) => strtolower($p->currency) === $currency);

                if ($candidates->isEmpty()) {
                    Log::warning('Boxly Protection has no active Stripe price in the billing currency', [
                        'product'  => $productId,
                        'currency' => $currency,
                    ]);
                    return null;
                }

                // Prefer the product's default price when it is in the right
                // currency; otherwise the highest, which is the list price.
                $price = $candidates->firstWhere('id', $product->default_price)
                    ?? $candidates->sortByDesc('unit_amount')->first();

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
