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

            // Format products from prices
            $products = collect($prices->data)
                ->filter(fn($price) => $price->product->active)
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
        ];
    }
}