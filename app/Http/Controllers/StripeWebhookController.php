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
        
        Log::info('Invoice Paid Webhook', ['id' => $invoice->id, 'metadata' => $metadata]);

        $type = $metadata['type'] ?? null;

        // 1. Handle Deposit Payment (First 50%)
        if ($type === 'deposit' && isset($metadata['order_id'])) {
            $order = Order::find($metadata['order_id']);
            if ($order) {
                $newAmount = $invoice->amount_paid / 100;

                $order->update([
                    'deposit_paid_at' => now(),
                    'amount_paid' => ($order->amount_paid ?? 0) + $newAmount,
                ]);
                
                Log::info('Order deposit paid', ['order_id' => $order->id]);

                // SEND DEPOSIT EMAIL
                try {
                    Mail::to($order->user)->queue(new DepositReceived($order));
                } catch (\Exception $e) {
                    Log::error('Failed to queue deposit email', ['error' => $e->getMessage()]);
                }
            }
            return;
        }

        // 2. Handle Final Order Payment (Remaining 50% + Extras)
        if (($type === 'final_invoice' || $type === 'order_invoice') && isset($metadata['order_id'])) {
            $this->handleOrderPaid($invoice, $metadata);
            return;
        }

        // 3. Handle Purchase Request
        if (isset($metadata['purchase_request_id']) && $type === 'purchase_request_invoice') {
            $this->handlePurchaseRequestPaid($invoice, $metadata);
            return;
        }
    }

    protected function handleOrderPaid($invoice, $metadata)
    {
        $order = Order::find($metadata['order_id']);
        
        if (!$order || $order->isPaid()) return;
        
        try {
            $newAmount = $invoice->amount_paid / 100;

            $order->update([
                'status' => Order::STATUS_PAID,
                'amount_paid' => ($order->amount_paid ?? 0) + $newAmount, 
                'paid_at' => now(),
                'stripe_payment_intent_id' => $invoice->payment_intent
            ]);
            
            Log::info('Order fully paid', ['order_id' => $order->id]);
            
            Mail::to($order->user)->send(new PaymentReceived($order));
            
        } catch (\Exception $e) {
            Log::error('Order paid handling failed', ['error' => $e->getMessage()]);
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