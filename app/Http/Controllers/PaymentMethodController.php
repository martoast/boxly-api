<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;
use Illuminate\Support\Facades\Log;

class PaymentMethodController extends Controller
{
    /**
     * Create a Stripe checkout session for adding a payment method
     */
    public function createSetupSession(Request $request)
    {
        $user = $request->user();
        
        try {
            // Ensure user has a Stripe customer ID
            if (!$user->stripe_id) {
                $user->createAsStripeCustomer([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
            }

            // Create a setup mode checkout session
            $session = Cashier::stripe()->checkout->sessions->create([
                'customer' => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode' => 'setup',
                'success_url' => config('app.frontend_url') . '/app/account?payment_method=success',
                'cancel_url' => config('app.frontend_url') . '/app/account?payment_method=cancelled',
                'metadata' => [
                    'user_id' => $user->id,
                    'type' => 'payment_method_setup',
                ],
                'locale' => 'es-419', // Spanish for Latin America
            ]);

            Log::info('Payment method setup session created', [
                'session_id' => $session->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create payment method setup session', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment method setup session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a setup intent for adding payment method (alternative method)
     */
    public function createSetupIntent(Request $request)
    {
        $user = $request->user();
        
        try {
            // Ensure user has a Stripe customer ID
            if (!$user->stripe_id) {
                $user->createAsStripeCustomer([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
            }

            // Create a setup intent using Cashier
            $intent = $user->createSetupIntent();

            return response()->json([
                'success' => true,
                'client_secret' => $intent->client_secret,
                'intent_id' => $intent->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create setup intent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create setup intent',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Add a payment method (for manual addition via setup intent)
     */
    public function store(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
            'set_as_default' => 'boolean'
        ]);

        $user = $request->user();
        
        try {
            // Add the payment method
            $user->addPaymentMethod($request->payment_method_id);
            
            // Always set as default (or if explicitly requested)
            $user->updateDefaultPaymentMethod($request->payment_method_id);

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to add payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment method',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get user's payment methods
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        try {
            // Get payment methods using Cashier
            $paymentMethods = $user->paymentMethods();
            
            // Get default payment method
            $defaultPaymentMethod = $user->defaultPaymentMethod();
            
            // Format the response
            $methods = $paymentMethods->map(function ($method) use ($defaultPaymentMethod) {
                return [
                    'id' => $method->id,
                    'brand' => $method->card->brand,
                    'last4' => $method->card->last4,
                    'exp_month' => $method->card->exp_month,
                    'exp_year' => $method->card->exp_year,
                    'is_default' => $defaultPaymentMethod && $defaultPaymentMethod->id === $method->id,
                    'created_at' => $method->created,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $methods,
                'has_default' => $user->hasDefaultPaymentMethod()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch payment methods', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete a payment method
     */
    public function destroy(Request $request, $paymentMethodId)
    {
        $user = $request->user();
        
        try {
            // Get all payment methods to check if this is the only one
            $allPaymentMethods = $user->paymentMethods();
            
            // Check if this is the default payment method
            $defaultPaymentMethod = $user->defaultPaymentMethod();
            $isDefault = $defaultPaymentMethod && $defaultPaymentMethod->id === $paymentMethodId;
            
            // If it's the default and there are other payment methods, prevent deletion
            if ($isDefault && $allPaymentMethods->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please set another payment method as default before deleting this one'
                ], 400);
            }

            // Delete the payment method
            $user->deletePaymentMethod($paymentMethodId);

            return response()->json([
                'success' => true,
                'message' => 'Payment method removed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete payment method', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove payment method',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Set a payment method as default
     */
    public function setDefault(Request $request, $paymentMethodId)
    {
        $user = $request->user();
        
        try {
            // Update default payment method
            $user->updateDefaultPaymentMethod($paymentMethodId);

            return response()->json([
                'success' => true,
                'message' => 'Default payment method updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to set default payment method', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update default payment method',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}