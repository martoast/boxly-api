<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCreated;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Facades\Notification;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook events
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('cashier.webhook.secret');

        Log::info('Webhook received', [
            'has_signature' => !empty($sigHeader),
            'webhook_secret_configured' => !empty($webhookSecret),
            'webhook_secret_starts_with' => substr($webhookSecret, 0, 10) . '...',
        ]);

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            Log::info('Webhook event constructed successfully', ['type' => $event->type]);
        } catch (SignatureVerificationException $e) {
            Log::error('Webhook signature verification failed', [
                'error' => $e->getMessage(),
                'signature' => $sigHeader,
                'secret_configured' => !empty($webhookSecret)
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Webhook error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook error'], 400);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutSessionCompleted($event);
                break;
                
            default:
                // Unhandled event type
                Log::info('Unhandled Stripe webhook event', ['type' => $event->type]);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle successful checkout session
     */
    protected function handleCheckoutSessionCompleted(Event $event)
    {
        $session = $event->data->object;
        
        Log::info('Processing checkout.session.completed', [
            'session_id' => $session->id,
            'metadata' => $session->metadata ? $session->metadata->toArray() : null
        ]);
        
        // Get user from metadata
        $userId = $session->metadata->user_id ?? null;
        $user = User::find($userId);
        
        if (!$user) {
            Log::error('User not found for checkout session: ' . $session->id);
            return;
        }

        // Check if order already exists (prevent duplicates)
        $existingOrder = Order::where('stripe_checkout_session_id', $session->id)->first();
        if ($existingOrder) {
            Log::info('Order already exists for session: ' . $session->id);
            return;
        }

        // Parse delivery address from metadata
        $deliveryAddress = [];
        if (isset($session->metadata->delivery_address)) {
            $deliveryAddress = json_decode($session->metadata->delivery_address, true);
            
            // Validate the decoded address
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse delivery address for session: ' . $session->id);
                $deliveryAddress = [];
            }
        }

        // Calculate rural surcharge if applicable
        $isRural = $session->metadata->is_rural === 'true';
        $ruralSurcharge = $isRural ? 20 : null;

        // Create the order with tracking and order numbers
        try {
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_COLLECTING,
                'box_size' => $session->metadata->box_type,
                'box_price' => $session->amount_subtotal / 100, // This will be just the box price
                'is_rural' => $isRural,
                'rural_surcharge' => $ruralSurcharge,
                'declared_value' => floatval($session->metadata->declared_value ?? 0),
                'iva_amount' => floatval($session->metadata->iva_amount ?? 0),
                'stripe_product_id' => $session->metadata->product_id,
                'stripe_price_id' => $session->metadata->price_id,
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent,
                'amount_paid' => $session->amount_total / 100, // This includes box + IVA + rural
                'currency' => $session->currency,
                'delivery_address' => $deliveryAddress,
                'paid_at' => now(),
            ]);

            Log::info('Order created successfully from checkout session', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'session_id' => $session->id,
                'user_id' => $user->id,
                'box_type' => $order->box_size,
                'amount_paid' => $order->amount_paid,
            ]);

            // Send confirmation email to customer
            Mail::to($user)->send(new OrderCreated($order));
            Log::info('Order confirmation email sent to customer', [
                'order_id' => $order->id,
                'user_email' => $user->email
            ]);

            // Notify admin of new order
            $admins = User::where('role', 'admin')->get();
            if ($admins->count() > 0) {
                Notification::send($admins, new NewOrderNotification($order));
                Log::info('Admin notification sent', [
                    'order_id' => $order->id,
                    'admin_count' => $admins->count()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to create order from checkout session', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}