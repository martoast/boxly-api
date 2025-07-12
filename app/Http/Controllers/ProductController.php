<?php

namespace App\Http\Controllers;

use Laravel\Cashier\Cashier;

class ProductController extends Controller
{
    /**
     * Get available products with prices from Stripe
     */
    public function index()
    {
        try {
            $prices = Cashier::stripe()->prices->search([
                'query' => 'active:"true"',
                'expand' => ['data.product'],
                'limit' => 100
            ]);

            $products = collect($prices->data)
                ->filter(function ($price) {
                    // Only show active products with box types
                    return $price->product->active && 
                           isset($price->product->metadata->type) &&
                           in_array($price->product->metadata->type, ['small', 'medium', 'large']);
                })
                ->map(function ($price) {
                    return [
                        'id' => $price->product->id,
                        'price_id' => $price->id,
                        'name' => $price->product->name,
                        'description' => $price->product->description,
                        'price' => $price->unit_amount / 100, // Convert from cents
                        'currency' => $price->currency,
                        'type' => $price->product->metadata->type,
                        'dimensions' => $price->product->metadata->dimensions ?? null,
                    ];
                })
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}