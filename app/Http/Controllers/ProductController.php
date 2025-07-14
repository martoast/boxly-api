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
                           in_array($price->product->metadata->type, ['small', 'medium', 'large']);
                })
                ->map(function ($price) {
                    $boxType = $price->product->metadata->type;
                    
                    // Get metadata from the product
                    $metadata = $price->product->metadata;
                    
                    return [
                        'id' => $price->product->id,
                        'price_id' => $price->id,
                        'stripe_price_id' => $price->id, // Add this for consistency with frontend
                        'name' => $price->product->name,
                        'description' => $price->product->description,
                        'price' => $price->unit_amount / 100, // Convert from cents
                        'currency' => strtoupper($price->currency),
                        'type' => $boxType,
                        'metadata' => [
                            'type' => $boxType,
                            'dimensions' => $metadata->dimensions ?? null,
                            'max_length' => $metadata->max_length ?? null,
                            'max_width' => $metadata->max_width ?? null,
                            'max_height' => $metadata->max_height ?? null,
                            'volumetric_weight' => $metadata->volumetric_weight ?? null,
                        ],
                        // Keep these for backward compatibility
                        'dimensions' => $metadata->dimensions ?? null,
                        'volumetric_weight' => $metadata->volumetric_weight ?? null,
                    ];
                })
                ->sortBy(function ($product) {
                    // Sort by size order
                    $order = ['small' => 1, 'medium' => 2, 'large' => 3];
                    return $order[$product['type']] ?? 5;
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
                'data' => $products, // Changed to return just the array for frontend compatibility
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