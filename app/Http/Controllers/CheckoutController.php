<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    /**
     * Create a Stripe checkout session for a box
     */
    public function createCheckout(Request $request)
    {
        $request->validate([
            'price_id' => 'required|string',
            'is_rural' => 'boolean',
            'declared_value' => 'required|numeric|min:1|max:99999',
            'delivery_address' => 'required|array',
            'delivery_address.street' => 'required|string|max:255',
            'delivery_address.exterior_number' => 'required|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'required|string|max:100',
            'delivery_address.municipio' => 'required|string|max:100',
            'delivery_address.estado' => 'required|string|max:100',
            'delivery_address.postal_code' => 'required|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        // Ensure user has Stripe customer ID
        if (!$user->stripe_id) {
            $user->createAsStripeCustomer([
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => [
                    'line1' => $request->delivery_address['street'] . ' ' . $request->delivery_address['exterior_number'],
                    'line2' => $request->delivery_address['interior_number'] ?? null,
                    'city' => $request->delivery_address['municipio'],
                    'state' => $request->delivery_address['estado'],
                    'postal_code' => $request->delivery_address['postal_code'],
                    'country' => 'MX',
                ],
            ]);
        }

        try {
            // Get the price details from Stripe
            $price = Cashier::stripe()->prices->retrieve($request->price_id, [
                'expand' => ['product']
            ]);

            // Validate this is one of our box products
            $validBoxTypes = ['extra-small', 'small', 'medium', 'large', 'extra-large'];
            $boxType = $price->product->metadata->type ?? null;
            
            if (!in_array($boxType, $validBoxTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid product selected'
                ], 400);
            }

            // Get box metadata
            $boxMetadata = $price->product->metadata;

            // Calculate IVA (16% of declared value) - ONLY if declared value >= $50 USD
            $declaredValue = floatval($request->declared_value);
            $ivaAmount = 0;
            
            // IVA only applies when declared value is $50 USD or more
            if ($declaredValue >= 50) {
                $ivaAmount = round($declaredValue * 0.16, 2);
            }
            
            // Build line items array
            $lineItems = [];
            
            // 1. Main box product
            $lineItems[$request->price_id] = 1; // price_id => quantity
            
            // 2. IVA as a dynamic price line item - ONLY if applicable
            if ($ivaAmount > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => intval($ivaAmount * 100), // Convert to cents
                        'product_data' => [
                            'name' => 'IVA (16% Import Tax)',
                            'description' => sprintf('16%% IVA on declared value of $%.2f USD', $declaredValue),
                        ],
                    ],
                    'quantity' => 1,
                ];
            }
            
            // 3. Rural surcharge if applicable
            if ($request->is_rural) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => 2000, // $20.00 in cents
                        'product_data' => [
                            'name' => 'Rural Delivery Surcharge',
                            'description' => 'Additional charge for delivery to rural areas in Mexico',
                        ],
                    ],
                    'quantity' => 1,
                ];
            }

            // Create checkout session using the checkout method
            $checkout = $user->checkout($lineItems, [
                'success_url' => config('app.frontend_url') . '/app/orders/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => config('app.frontend_url') . '/app/orders/create',
                'payment_method_types' => ['card', 'link'],
                'metadata' => [
                    'user_id' => $user->id,
                    'box_type' => $boxType,
                    'is_rural' => $request->is_rural ? 'true' : 'false',
                    'product_id' => $price->product->id,
                    'price_id' => $price->id,
                    'declared_value' => strval($declaredValue),
                    'iva_amount' => strval($ivaAmount),
                    'delivery_address' => json_encode($request->delivery_address),
                    // Add box dimensions to metadata
                    'box_dimensions' => $boxMetadata->dimensions ?? null,
                    'box_max_length' => $boxMetadata->max_length ?? null,
                    'box_max_width' => $boxMetadata->max_width ?? null,
                    'box_max_height' => $boxMetadata->max_height ?? null,
                    'box_max_weight' => $boxMetadata->max_weight ?? null,
                ],
                'payment_intent_data' => [
                    'description' => sprintf(
                        'Package consolidation box (%s) for %s',
                        $boxType,
                        $user->name
                    ),
                    'metadata' => [
                        'user_id' => $user->id,
                        'box_type' => $boxType,
                        'customer_name' => $user->name,
                    ],
                ],
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                'allow_promotion_codes' => true,
                'locale' => 'es-419', // Spanish for Latin America
                'automatic_tax' => [
                    'enabled' => false,
                ],
            ]);

            // Calculate total for response
            $boxPrice = $price->unit_amount / 100;
            $ruralCharge = $request->is_rural ? 20 : 0;
            $totalAmount = $boxPrice + $ivaAmount + $ruralCharge;

            // Log checkout creation for monitoring
            Log::info('Checkout session created', [
                'session_id' => $checkout->id,
                'user_id' => $user->id,
                'total_amount' => $totalAmount,
                'box_type' => $boxType,
                'declared_value' => $declaredValue,
                'iva_applied' => $ivaAmount > 0,
                'iva_amount' => $ivaAmount,
            ]);

            return response()->json([
                'success' => true,
                'checkout_url' => $checkout->url,
                'session_id' => $checkout->id,
                'breakdown' => [
                    'box_price' => $boxPrice,
                    'declared_value' => $declaredValue,
                    'iva_amount' => $ivaAmount,
                    'rural_surcharge' => $ruralCharge,
                    'total' => $totalAmount,
                    'currency' => 'USD'
                ]
            ]);

        } catch (\Laravel\Cashier\Exceptions\IncompletePayment $e) {
            Log::warning('Incomplete payment detected', [
                'payment_id' => $e->payment->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'requires_action' => true,
                'payment_intent_id' => $e->payment->id,
                'confirmation_url' => route('cashier.payment', [
                    $e->payment->id,
                    'redirect' => config('app.frontend_url') . '/app/orders/success'
                ]),
                'message' => 'Payment requires additional confirmation'
            ], 402);

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::error('Invalid Stripe request: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment configuration',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('Checkout creation failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}