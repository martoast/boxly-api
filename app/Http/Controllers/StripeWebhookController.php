<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceived;
use App\Mail\PurchaseRequestPaymentReceived;
use App\Notifications\OrderPaidNotification;
use Illuminate\Support\Facades\Notification;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('cashier.webhook.secret');

        Log::info('Webhook received', [
            'has_signature' => !empty($sigHeader),
            'webhook_secret_configured' => !empty($webhookSecret),
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
            case 'invoice.paid':
                $this->handleInvoicePaid($event);
                break;
                
            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event);
                break;
                
            case 'invoice.payment_action_required':
                $this->handleInvoicePaymentActionRequired($event);
                break;
                
            case 'invoice.voided':
                $this->handleInvoiceVoided($event);
                break;
                
            default:
                Log::info('Unhandled Stripe webhook event', ['type' => $event->type]);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Main router for paid invoices. 
     * Distinguishes between Purchase Requests and Standard Orders.
     */
    protected function handleInvoicePaid(Event $event)
    {
        $invoice = $event->data->object;
        
        // SAFE ACCESS: Convert metadata to array if it exists
        $metadata = isset($invoice->metadata) ? $invoice->metadata->toArray() : [];
        
        Log::info('Processing invoice.paid event', [
            'invoice_id' => $invoice->id,
            'amount_paid' => $invoice->amount_paid,
            'metadata' => $metadata
        ]);

        $type = $metadata['type'] ?? null;

        // 1. Handle Assisted Purchase Request Payment
        if (isset($metadata['purchase_request_id']) && $type === 'purchase_request_invoice') {
            $this->handlePurchaseRequestPaid($invoice, $metadata);
            return;
        }

        // 2. Handle Standard Order Shipping Payment
        if (isset($metadata['order_id']) && in_array($type, ['order_quote', 'order_invoice'])) {
            $this->handleOrderPaid($invoice, $metadata);
            return;
        }

        Log::info('Invoice paid but no matching handler found in metadata', [
            'invoice_id' => $invoice->id,
            'metadata_dump' => $metadata
        ]);
    }

    /**
     * Logic for Assisted Purchase Requests (New Module)
     */
    protected function handlePurchaseRequestPaid($invoice, $metadata)
    {
        $requestId = $metadata['purchase_request_id'];
        $purchaseRequest = PurchaseRequest::find($requestId);

        if (!$purchaseRequest) {
            Log::error('Purchase Request not found for paid invoice', [
                'invoice_id' => $invoice->id,
                'request_id' => $requestId
            ]);
            return;
        }

        if ($purchaseRequest->status === PurchaseRequest::STATUS_PAID) {
            Log::info('Purchase Request already marked paid, skipping', [
                'request_id' => $purchaseRequest->id
            ]);
            return;
        }

        try {
            $purchaseRequest->update([
                'status' => PurchaseRequest::STATUS_PAID,
                'paid_at' => now(),
            ]);

            Log::info('Purchase Request marked as paid', [
                'request_id' => $purchaseRequest->id,
                'request_number' => $purchaseRequest->request_number,
                'amount_total' => $invoice->amount_paid / 100
            ]);

            // SEND EMAIL NOTIFICATION (CRITICAL FIX)
            try {
                Mail::to($purchaseRequest->user)->queue(new PurchaseRequestPaymentReceived($purchaseRequest));
                Log::info('Payment received email queued for ' . $purchaseRequest->user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue payment received email: ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            Log::error('Failed to update Purchase Request status to paid', [
                'request_id' => $purchaseRequest->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Logic for Standard Orders (Refactored existing logic)
     */
    protected function handleOrderPaid($invoice, $metadata)
    {
        $orderId = $metadata['order_id'];
        $order = Order::find($orderId);
        
        if (!$order) {
            Log::error('Order not found for paid invoice', [
                'invoice_id' => $invoice->id,
                'order_id' => $orderId
            ]);
            return;
        }
        
        if ($order->isPaid()) {
            Log::info('Order already paid, skipping', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id
            ]);
            return;
        }
        
        try {
            $order->update([
                'status' => Order::STATUS_PAID,
                'amount_paid' => $invoice->amount_paid / 100,
                'paid_at' => now(),
                'stripe_payment_intent_id' => $invoice->payment_intent
            ]);
            
            Log::info('Order marked as paid from invoice', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
            ]);
            
            // Send Customer Email
            try {
                Mail::to($order->user)->send(new PaymentReceived($order));
                Log::info('Payment confirmation email sent to customer', [
                    'order_id' => $order->id,
                    'user_email' => $order->user->email
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send payment email', ['error' => $e->getMessage()]);
            }
            
            // Send Admin Notification
            $admins = User::where('role', 'admin')->get();
            if ($admins->count() > 0) {
                try {
                    Notification::send($admins, new OrderPaidNotification($order));
                } catch (\Exception $e) {
                    Log::error('Failed to send admin notification', ['error' => $e->getMessage()]);
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to update order status to paid', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function handleInvoicePaymentFailed(Event $event)
    {
        $invoice = $event->data->object;
        $metadata = isset($invoice->metadata) ? $invoice->metadata->toArray() : [];
        
        Log::warning('Invoice payment failed', [
            'invoice_id' => $invoice->id,
            'metadata' => $metadata
        ]);
        
        // Handle Order Failure
        if (isset($metadata['order_id'])) {
            $order = Order::find($metadata['order_id']);
            if ($order) {
                Log::warning('Order payment failed', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }
        }
        
        // Handle Purchase Request Failure
        if (isset($metadata['purchase_request_id'])) {
            $pr = PurchaseRequest::find($metadata['purchase_request_id']);
            if ($pr) {
                Log::warning('Purchase Request payment failed', [
                    'request_id' => $pr->id,
                    'request_number' => $pr->request_number,
                ]);
            }
        }
    }

    protected function handleInvoicePaymentActionRequired(Event $event)
    {
        $invoice = $event->data->object;
        Log::info('Invoice requires payment action', [
            'invoice_id' => $invoice->id
        ]);
    }

    protected function handleInvoiceVoided(Event $event)
    {
        $invoice = $event->data->object;
        $metadata = isset($invoice->metadata) ? $invoice->metadata->toArray() : [];
        
        Log::info('Invoice voided', [
            'invoice_id' => $invoice->id,
            'type' => $metadata['type'] ?? 'unknown'
        ]);
        
        // Handle Order Invoice Void (Revert status)
        if (isset($metadata['order_id']) && isset($metadata['type']) && in_array($metadata['type'], ['order_quote', 'order_invoice'])) {
            $order = Order::find($metadata['order_id']);
            
            if ($order && $order->status === Order::STATUS_AWAITING_PAYMENT) {
                $order->update([
                    'status' => Order::STATUS_DELIVERED,
                    'stripe_invoice_id' => null,
                    'payment_link' => null,
                    'quote_sent_at' => null,
                    'quote_expires_at' => null,
                ]);
                
                Log::info('Order invoice cancelled (invoice voided), status reverted to Delivered', [
                    'order_id' => $order->id,
                ]);
            }
        }

        // Handle Purchase Request Void (Revert status)
        if (isset($metadata['purchase_request_id']) && ($metadata['type'] ?? '') === 'purchase_request_invoice') {
            $pr = PurchaseRequest::find($metadata['purchase_request_id']);
            
            if ($pr && $pr->status === PurchaseRequest::STATUS_QUOTED) {
                $pr->update([
                    'status' => PurchaseRequest::STATUS_PENDING_REVIEW, 
                    'stripe_invoice_id' => null,
                    'payment_link' => null,
                    'quote_sent_at' => null,
                ]);

                Log::info('Purchase Request invoice voided, status reverted', [
                    'request_id' => $pr->id
                ]);
            }
        }
    }
}