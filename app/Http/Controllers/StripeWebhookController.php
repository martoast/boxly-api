<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

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

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Webhook error'], 400);
        }

        switch ($event->type) {
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event);
                break;
                
            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event);
                break;
                
            case 'invoice.sent':
                $this->handleInvoiceSent($event);
                break;
                
            default:
                // Unhandled event type
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle successful invoice payment
     */
    protected function handleInvoicePaymentSucceeded(Event $event)
    {
        $invoice = $event->data->object;
        
        // Find order by invoice ID
        $order = Order::where('stripe_invoice_id', $invoice->id)->first();
        
        if ($order && $order->status === Order::STATUS_QUOTE_SENT) {
            $order->update([
                'status' => Order::STATUS_PAID,
                'amount_paid' => $invoice->amount_paid / 100, // Convert from cents
                'stripe_payment_intent_id' => $invoice->payment_intent,
                'paid_at' => now()
            ]);
            
            // TODO: Send notification to admin about new paid order
            // Notification::send($admins, new OrderPaidNotification($order));
            
            // TODO: Send confirmation to customer
            // Mail::to($order->user)->send(new OrderPaidConfirmation($order));
        }
    }

    /**
     * Handle failed invoice payment
     */
    protected function handleInvoicePaymentFailed(Event $event)
    {
        $invoice = $event->data->object;
        
        // Find order by invoice ID
        $order = Order::where('stripe_invoice_id', $invoice->id)->first();
        
        if ($order) {
            // TODO: Send notification to customer about failed payment
            // Mail::to($order->user)->send(new PaymentFailedNotification($order));
        }
    }

    /**
     * Handle invoice sent event
     */
    protected function handleInvoiceSent(Event $event)
    {
        $invoice = $event->data->object;
        
        // Log that invoice was sent
        \Log::info('Invoice sent', [
            'invoice_id' => $invoice->id,
            'customer' => $invoice->customer,
            'amount' => $invoice->amount_due / 100
        ]);
    }
}