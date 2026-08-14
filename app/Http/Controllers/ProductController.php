<?php

namespace App\Http\Controllers;

use Laravel\Cashier\Cashier;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Get all products with prices from Stripe
     */
    public function index()
    {
        try {
            // Fetch all active prices with expanded product data
            $prices = Cashier::stripe()->prices->all([
                'active' => true,
                'expand' => ['data.product'],
                'limit' => 100
            ]);

            // ONLY prices in the currency we actually bill in.
            //
            // A Stripe product can carry prices in several currencies at once —
            // Boxly Protection currently has both 200 MXN and 11.60 USD. This
            // endpoint returned both, so any client doing find(is_protection)
            // got whichever Stripe happened to list first: the USD one. The
            // admin order pages showed "+$11.60" beside a $4,400 MXN box.
            // (Customers were always charged correctly — the amount billed comes
            // from ProtectionProduct::price(), which already filters this way.)
            //
            // Filtering here rather than in each client because the constraint
            // is real, not cosmetic: an invoice has ONE currency, so a price in
            // any other currency can never legally go on it. A number this
            // endpoint hands out that cannot be charged is not useful to anyone.
            $currency = strtolower(config('cashier.currency') ?: 'mxn');

            $active = collect($prices->data)->filter(fn($price) => $price->product->active);
            $inCurrency = $active->filter(fn($price) => strtolower($price->currency) === $currency);

            // config/cashier.php defaults CASHIER_CURRENCY to 'usd'; production
            // sets it to mxn. If that env var is ever missing, filtering strictly
            // would hand back an empty catalog and the admin would have no boxes
            // to consolidate with — a far worse failure than the label it fixes.
            // So an empty result means the filter is wrong, not the catalog.
            if ($inCurrency->isEmpty() && $active->isNotEmpty()) {
                Log::warning('No active Stripe prices in the billing currency — serving unfiltered', [
                    'currency' => $currency,
                ]);
                $inCurrency = $active;
            }

            $products = $inCurrency
                ->map(function ($price) {
                    return $this->formatPrice($price);
                })
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get a single product by its Price ID
     */
    public function show($priceId)
    {
        try {
            $price = Cashier::stripe()->prices->retrieve($priceId, [
                'expand' => ['product']
            ]);

            if (!$price || !$price->active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found or inactive'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatPrice($price)
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching product',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Helper to format price object into simplified product array
     */
    private function formatPrice($price)
    {
        $product = $price->product;
        
        return [
            'id' => $product->id,
            'price_id' => $price->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $price->unit_amount / 100,
            'currency' => strtoupper($price->currency),
            'min_weight' => $product->metadata->min_weight ?? null,
            'max_weight' => $product->metadata->max_weight ?? null,
            'max_length' => $product->metadata->max_length ?? null,
            'max_height' => $product->metadata->max_height ?? null,
            'max_width' => $product->metadata->max_width ?? null,
            'consolidated' => $product->metadata->consolidated ?? null,
            'shipping' => $product->metadata->shipping ?? null,
            // Boxly Protection has no metadata of its own, so the client can't
            // pick it out of the list by shape. Flagging it here keeps the
            // product id in config on the server instead of hardcoded in the UI.
            'is_protection' => $product->id === config('services.boxly_protection.product_id'),
        ];
    }
}