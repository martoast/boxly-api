<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseRequest;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\Product;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Webhook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Mail\PaymentReceived;
use App\Mail\DepositReceived;
use App\Mail\PurchaseRequestPaymentReceived;
use App\Mail\StoreSaleTeamNotification;
use App\Models\User;

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
            Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
                'has_secret' => !empty($webhookSecret),
            ]);
            return response()->json(['error' => 'Webhook Error: ' . $e->getMessage()], 400);
        }

        if ($event->type === 'invoice.paid') {
            $this->handleInvoicePaid($event);
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutSessionCompleted($event);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle a completed Stripe Checkout Session — fired for Boxly Store purchases.
     * The store checkout creates a PurchaseRequest in `quoted` status; when the
     * Checkout Session completes we flip it to `paid` so the admin can buy it
     * from the source store via the existing PR flow.
     */
    protected function handleCheckoutSessionCompleted(Event $event)
    {
        $session = $event->data->object;
        $metadata = isset($session->metadata) ? $session->metadata->toArray() : [];
        $type = $metadata['type'] ?? null;

        Log::info('Checkout Session Completed Webhook', [
            'session_id' => $session->id,
            'type' => $type,
            'metadata' => $metadata,
        ]);

        if ($type === 'store_purchase' && isset($metadata['purchase_request_id'])) {
            $this->handleStorePurchasePaid($session, $metadata);
        }
    }

    protected function handleStorePurchasePaid($session, array $metadata)
    {
        $pr = PurchaseRequest::find($metadata['purchase_request_id']);
        if (! $pr) {
            Log::warning('Purchase request not found for store checkout completion', [
                'purchase_request_id' => $metadata['purchase_request_id'],
            ]);
            return;
        }

        if ($pr->status === PurchaseRequest::STATUS_PAID) return;

        try {
            $pr->update([
                'status'  => PurchaseRequest::STATUS_PAID,
                'paid_at' => now(),
            ]);

            try {
                Mail::to($pr->user)->queue(new PurchaseRequestPaymentReceived($pr));
            } catch (\Exception $e) {
                Log::error('Failed to queue store purchase paid email', ['error' => $e->getMessage()]);
            }

            $this->notifyShoppingTeamOfPaidPR($pr);

            Log::info('Store purchase paid', [
                'purchase_request_id' => $pr->id,
                'session_id'          => $session->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle store purchase paid', [
                'session_id' => $session->id,
                'error'      => $e->getMessage(),
            ]);
        }
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

                // Track affiliate conversion
                $this->trackAffiliateConversion($order);
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

        // Log unhandled invoice types for debugging
        Log::warning('Invoice paid webhook received but no handler matched', [
            'invoice_id' => $invoice->id,
            'type' => $type,
            'metadata' => $metadata,
            'amount_paid' => $invoice->amount_paid,
        ]);
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

            // Track affiliate conversion
            $this->trackAffiliateConversion($order);

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

            $this->notifyShoppingTeamOfPaidPR($pr);
        } catch (\Exception $e) {
            Log::error('PR paid handling failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Internal alert to the shopping team when a PR (store-checkout OR
     * assisted) flips to paid. Admins are excluded — they use the dashboard.
     * Failures are logged, never thrown — the PR status flip is the
     * critical write; emails are best-effort.
     */
    protected function notifyShoppingTeamOfPaidPR(PurchaseRequest $pr): void
    {
        try {
            $pr->load(['items', 'user']);
            $teamEmails = User::query()
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('team', User::TEAM_SHOPPING)
                ->pluck('email')
                ->all();

            if (! empty($teamEmails)) {
                Mail::to($teamEmails)->queue(new StoreSaleTeamNotification($pr));
            }
        } catch (\Exception $e) {
            Log::error('Failed to queue shopping team paid-PR notification', [
                'pr_id' => $pr->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track affiliate conversion when an order is paid.
     * Creates a conversion record if the user was referred by an affiliate.
     */
    protected function trackAffiliateConversion(Order $order): void
    {
        try {
            $user = $order->user;

            // Check if user was referred by an affiliate
            $referral = $user->affiliateReferral;
            if (!$referral) {
                return;
            }

            // Check if conversion already exists for this order
            if (AffiliateConversion::where('order_id', $order->id)->exists()) {
                Log::info('Affiliate conversion already exists', ['order_id' => $order->id]);
                return;
            }

            $affiliate = $referral->affiliate;

            // Only track for active affiliates
            if (!$affiliate->isActive()) {
                Log::info('Affiliate is not active, skipping conversion', [
                    'affiliate_id' => $affiliate->id,
                    'order_id' => $order->id,
                ]);
                return;
            }

            // Calculate commission based on total box price
            $boxPrice = $order->calculateTotalBoxPrice();
            $commissionAmount = $affiliate->calculateCommission($boxPrice);

            DB::transaction(function () use ($affiliate, $referral, $order, $boxPrice, $commissionAmount) {
                // Create conversion record
                AffiliateConversion::create([
                    'affiliate_id' => $affiliate->id,
                    'referral_id' => $referral->id,
                    'order_id' => $order->id,
                    'order_amount' => $boxPrice,
                    'commission_amount' => $commissionAmount,
                    'status' => AffiliateConversion::STATUS_APPROVED,
                ]);

                // Update affiliate's total earnings
                $affiliate->increment('total_earnings', $commissionAmount);
            });

            Log::info('Affiliate conversion tracked', [
                'affiliate_id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'order_id' => $order->id,
                'box_price' => $boxPrice,
                'commission_amount' => $commissionAmount,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => $affiliate->commission_value,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to track affiliate conversion', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}