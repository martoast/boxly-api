<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Webhook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceived;
use App\Mail\DepositReceived; // Ensure this is imported
use App\Mail\PurchaseRequestPaymentReceived;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('cashier.webhook.secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Webhook Error'], 400);
        }

        if ($event->type === 'invoice.paid') {
            $this->handleInvoicePaid($event);
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleInvoicePaid(Event $event)
    {
        $invoice = $event->data->object;
        $metadata = isset($invoice->metadata) ? $invoice->metadata->toArray() : [];

        Log::info('Invoice Paid Webhook', [
            'id' => $invoice->id,
            'metadata' => $metadata,
            'amount_paid' => $invoice->amount_paid,
        ]);

        $type = $metadata['type'] ?? null;

        // 1. Handle Box Payment (100% at consolidation) - NEW FLOW
        if ($type === 'box_payment' && isset($metadata['order_id'])) {
            $order = Order::find($metadata['order_id']);
            if ($order) {
                $newAmount = $invoice->amount_paid / 100;
                $boxCount = $metadata['box_count'] ?? 1;

                $order->update([
                    'paid_at' => now(),
                    'amount_paid' => ($order->amount_paid ?? 0) + $newAmount,
                    'status' => Order::STATUS_PAID, // Payment received, ready to ship
                ]);

                // Refresh order to get updated values for the email
                $order->refresh();

                Log::info('Order box payment received', [
                    'order_id' => $order->id,
                    'amount' => $newAmount,
                    'box_count' => $boxCount,
                    'total_box_price' => $metadata['total_box_price'] ?? null,
                    'status' => $order->status,
                ]);

                // Send payment confirmation email
                try {
                    Mail::to($order->user)->queue(new PaymentReceived($order));
                    Log::info('Payment received email queued', ['order_id' => $order->id]);
                } catch (\Exception $e) {
                    Log::error('Failed to queue box payment email', ['error' => $e->getMessage()]);
                }
            }
            return;
        }

        // 2. Handle Deposit Payment (First 50%) - LEGACY supports multiple boxes
        if ($type === 'deposit' && isset($metadata['order_id'])) {
            $order = Order::find($metadata['order_id']);
            if ($order) {
                $newAmount = $invoice->amount_paid / 100;
                $boxCount = $metadata['box_count'] ?? 1;

                $order->update([
                    'deposit_paid_at' => now(),
                    'amount_paid' => ($order->amount_paid ?? 0) + $newAmount,
                ]);

                Log::info('Order deposit paid', [
                    'order_id' => $order->id,
                    'amount' => $newAmount,
                    'box_count' => $boxCount,
                    'total_box_price' => $metadata['total_box_price'] ?? null,
                ]);

                // SEND DEPOSIT EMAIL
                try {
                    Mail::to($order->user)->queue(new DepositReceived($order));
                } catch (\Exception $e) {
                    Log::error('Failed to queue deposit email', ['error' => $e->getMessage()]);
                }
            }
            return;
        }

        // 3. Handle Final Order Payment (Remaining 50% + Extras) OR Full Payment (Crossing Orders) - LEGACY
        if (($type === 'final_invoice' || $type === 'order_invoice' || $type === 'full_payment') && isset($metadata['order_id'])) {
            $this->handleOrderPaid($invoice, $metadata);
            return;
        }

        // 4. Handle Purchase Request
        if (isset($metadata['purchase_request_id']) && $type === 'purchase_request_invoice') {
            $this->handlePurchaseRequestPaid($invoice, $metadata);
            return;
        }
    }

    protected function handleOrderPaid($invoice, $metadata)
    {
        $order = Order::find($metadata['order_id']);

        if (!$order) {
            Log::warning('Order not found for payment webhook', ['order_id' => $metadata['order_id'] ?? 'unknown']);
            return;
        }

        $newAmount = $invoice->amount_paid / 100;

        // Only skip if order is already paid AND amount_paid is set
        // This ensures we still update amount_paid even if order was manually marked as paid
        if ($order->isPaid() && !empty($order->amount_paid) && $order->amount_paid > 0) {
            Log::info('Order already fully paid, skipping webhook update', [
                'order_id' => $order->id,
                'existing_amount_paid' => $order->amount_paid,
            ]);
            return;
        }

        try {
            $boxCount = $metadata['box_count'] ?? 1;

            $order->update([
                'status' => Order::STATUS_PAID,
                'amount_paid' => ($order->amount_paid ?? 0) + $newAmount,
                'paid_at' => $order->paid_at ?? now(), // Keep existing paid_at if already set
                'stripe_payment_intent_id' => $invoice->payment_intent
            ]);

            // Refresh order to get updated values for the email
            $order->refresh();

            Log::info('Order fully paid', [
                'order_id' => $order->id,
                'amount' => $newAmount,
                'total_amount_paid' => $order->amount_paid,
                'box_count' => $boxCount,
                'total_box_price' => $metadata['total_box_price'] ?? null,
                'order_type' => $order->order_type ?? 'shipping',
                'status' => $order->status,
            ]);

            Mail::to($order->user)->queue(new PaymentReceived($order));
            Log::info('Payment received email queued', ['order_id' => $order->id]);

        } catch (\Exception $e) {
            Log::error('Order paid handling failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handlePurchaseRequestPaid($invoice, $metadata)
    {
        $pr = PurchaseRequest::find($metadata['purchase_request_id']);
        if (!$pr || $pr->status === PurchaseRequest::STATUS_PAID) return;

        try {
            $pr->update(['status' => PurchaseRequest::STATUS_PAID, 'paid_at' => now()]);
            Mail::to($pr->user)->queue(new PurchaseRequestPaymentReceived($pr));
        } catch (\Exception $e) {
            Log::error('PR paid handling failed', ['error' => $e->getMessage()]);
        }
    }
}