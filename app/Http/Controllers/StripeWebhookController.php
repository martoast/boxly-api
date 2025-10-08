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
use App\Mail\PaymentReceived;
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

    protected function handleInvoicePaid(Event $event)
    {
        $invoice = $event->data->object;
        
        Log::info('Processing invoice.paid event', [
            'invoice_id' => $invoice->id,
            'amount_paid' => $invoice->amount_paid,
            'metadata' => $invoice->metadata ? $invoice->metadata->toArray() : null
        ]);
        
        $metadataType = $invoice->metadata->type ?? null;
        if (!isset($invoice->metadata->order_id) || !in_array($metadataType, ['order_quote', 'order_invoice'])) {
            Log::info('Invoice is not for an order, skipping', [
                'invoice_id' => $invoice->id,
                'metadata_type' => $metadataType,
            ]);
            return;
        }
        
        $order = Order::find($invoice->metadata->order_id);
        
        if (!$order) {
            Log::error('Order not found for paid invoice', [
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->metadata->order_id
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
                'order_number' => $order->order_number,
                'invoice_id' => $invoice->id,
                'amount_paid' => $order->amount_paid,
            ]);
            
            Mail::to($order->user)->send(new PaymentReceived($order));
            Log::info('Payment confirmation email sent to customer', [
                'order_id' => $order->id,
                'user_email' => $order->user->email
            ]);
            
            $admins = User::where('role', 'admin')->get();
            if ($admins->count() > 0) {
                Notification::send($admins, new OrderPaidNotification($order));
                Log::info('Admin notification sent for paid order', [
                    'order_id' => $order->id,
                    'admin_count' => $admins->count()
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to update order status to paid', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function handleInvoicePaymentFailed(Event $event)
    {
        $invoice = $event->data->object;
        
        Log::warning('Invoice payment failed', [
            'invoice_id' => $invoice->id,
            'metadata' => $invoice->metadata ? $invoice->metadata->toArray() : null
        ]);
        
        $metadataType = $invoice->metadata->type ?? null;
        if (!isset($invoice->metadata->order_id) || !in_array($metadataType, ['order_quote', 'order_invoice'])) {
            return;
        }
        
        $order = Order::find($invoice->metadata->order_id);
        
        if (!$order) {
            Log::error('Order not found for failed invoice', [
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->metadata->order_id
            ]);
            return;
        }
        
        Log::warning('Order payment failed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_id' => $invoice->id,
        ]);
    }

    protected function handleInvoicePaymentActionRequired(Event $event)
    {
        $invoice = $event->data->object;
        
        Log::info('Invoice requires payment action', [
            'invoice_id' => $invoice->id,
            'metadata' => $invoice->metadata ? $invoice->metadata->toArray() : null
        ]);
        
        $metadataType = $invoice->metadata->type ?? null;
        if (!isset($invoice->metadata->order_id) || !in_array($metadataType, ['order_quote', 'order_invoice'])) {
            return;
        }
        
        $order = Order::find($invoice->metadata->order_id);
        
        if (!$order) {
            return;
        }
        
        Log::info('Order payment requires action', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_id' => $invoice->id,
        ]);
    }

    protected function handleInvoiceVoided(Event $event)
    {
        $invoice = $event->data->object;
        
        Log::info('Invoice voided', [
            'invoice_id' => $invoice->id,
            'metadata' => $invoice->metadata ? $invoice->metadata->toArray() : null
        ]);
        
        $metadataType = $invoice->metadata->type ?? null;
        if (!isset($invoice->metadata->order_id) || !in_array($metadataType, ['order_quote', 'order_invoice'])) {
            return;
        }
        
        $order = Order::find($invoice->metadata->order_id);
        
        if (!$order) {
            return;
        }
        
        if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
            $order->update([
                'status' => Order::STATUS_DELIVERED,
                'stripe_invoice_id' => null,
                'payment_link' => null,
                'quote_sent_at' => null,
                'quote_expires_at' => null,
            ]);
            
            Log::info('Order invoice cancelled (invoice voided)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_id' => $invoice->id,
            ]);
        }
    }
}