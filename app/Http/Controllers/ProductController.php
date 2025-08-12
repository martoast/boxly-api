<?php

namespace App\Http\Controllers;

use Laravel\Cashier\Cashier;
use Illuminate\Support\Facades\Log;

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
                    // Only show active products with weight metadata
                    return $price->product->active && 
                           isset($price->product->metadata->min_weight) &&
                           isset($price->product->metadata->max_weight);
                })
                ->groupBy('product.id') // Group by product ID to handle duplicates
                ->map(function ($pricesForProduct) {
                    // Take the most recent price (highest price_id typically)
                    $latestPrice = $pricesForProduct->sortByDesc('created')->first();
                    
                    // Get metadata from the product
                    $metadata = $latestPrice->product->metadata;
                    
                    return [
                        'id' => $latestPrice->product->id,
                        'price_id' => $latestPrice->id,
                        'stripe_price_id' => $latestPrice->id,
                        'name' => $latestPrice->product->name,
                        'description' => $latestPrice->product->description,
                        'price' => $latestPrice->unit_amount / 100, // Convert from cents
                        'currency' => strtoupper($latestPrice->currency),
                        'min_weight' => floatval($metadata->min_weight),
                        'max_weight' => floatval($metadata->max_weight),
                        'metadata' => [
                            'min_weight' => $metadata->min_weight,
                            'max_weight' => $metadata->max_weight,
                        ]
                    ];
                })
                ->sortBy('min_weight') // Sort by minimum weight
                ->values()
                ->toArray();

            // Also get rural surcharge if it exists
            $ruralProducts = Cashier::stripe()->products->search([
                'query' => 'metadata[\'type\']:\'rural_surcharge\' AND active:\'true\'',
                'expand' => ['data.default_price'],
                'limit' => 1
            ]);

            $ruralSurcharge = null;
            if ($ruralProducts->data) {
                $ruralProduct = $ruralProducts->data[0];
                if ($ruralProduct->default_price) {
                    $ruralSurcharge = [
                        'id' => $ruralProduct->id,
                        'price_id' => $ruralProduct->default_price->id,
                        'price' => $ruralProduct->default_price->unit_amount / 100,
                        'currency' => strtoupper($ruralProduct->default_price->currency),
                        'description' => $ruralProduct->description ?? 'Additional charge for rural delivery areas'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $products,
                'rural_surcharge' => $ruralSurcharge
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
}