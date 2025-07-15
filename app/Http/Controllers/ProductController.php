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
                    // Only show active products with box types
                    return $price->product->active && 
                           isset($price->product->metadata->type) &&
                           in_array($price->product->metadata->type, ['extra-small', 'small', 'medium', 'large', 'extra-large']);
                })
                ->groupBy('product.id') // Group by product ID to handle duplicates
                ->map(function ($pricesForProduct) {
                    // Take the most recent price (highest price_id typically)
                    $latestPrice = $pricesForProduct->sortByDesc('created')->first();
                    
                    $boxType = $latestPrice->product->metadata->type;
                    
                    // Get metadata from the product
                    $metadata = $latestPrice->product->metadata;
                    
                    // Build dimensions string if not already present
                    $dimensions = $metadata->dimensions ?? null;
                    if (!$dimensions && isset($metadata->max_length, $metadata->max_width, $metadata->max_height)) {
                        // Follow your format: length x width x height
                        $dimensions = $metadata->max_length . 'x' . $metadata->max_width . 'x' . $metadata->max_height . 'cm';
                    }
                    
                    return [
                        'id' => $latestPrice->product->id,
                        'price_id' => $latestPrice->id,
                        'stripe_price_id' => $latestPrice->id,
                        'name' => $latestPrice->product->name,
                        'description' => $latestPrice->product->description,
                        'price' => $latestPrice->unit_amount / 100, // Convert from cents
                        'currency' => strtoupper($latestPrice->currency),
                        'type' => $boxType,
                        'metadata' => [
                            'type' => $boxType,
                            'dimensions' => $dimensions,
                            'max_length' => $metadata->max_length ?? null,
                            'max_width' => $metadata->max_width ?? null,
                            'max_height' => $metadata->max_height ?? null,
                            'max_weight' => $metadata->max_weight ?? null,
                        ],
                        // Keep these for backward compatibility
                        'dimensions' => $dimensions,
                        'max_weight' => $metadata->max_weight ?? null,
                    ];
                })
                ->sortBy(function ($product) {
                    // Sort by size order
                    $order = [
                        'extra-small' => 1, 
                        'small' => 2, 
                        'medium' => 3, 
                        'large' => 4, 
                        'extra-large' => 5
                    ];
                    return $order[$product['type']] ?? 6;
                })
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