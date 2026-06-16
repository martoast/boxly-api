<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseRequest;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
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
    /**
     * Webhook for the MAIN Stripe account (boxes / shipping).
     *
     * Configured in Stripe Dashboard at:
     *   https://api.boxly.mx/webhooks/stripe
     */
    public function handle(Request $request)
    {
        return $this->process($request, config('cashier.webhook.secret'));
    }

    /**
     * Webhook for the SHOPPING Stripe account (Purchase Request invoices +
     * in-person deposits). Separate signing secret = separate verification.
     *
     * Configured in Stripe Dashboard at:
     *   https://api.boxly.mx/webhooks/stripe-shopping
     */
    public function handleShopping(Request $request)
    {
        return $this->process($request, config('services.stripe_shopping.webhook_secret'));
    }

    /**
     * Shared verification + dispatch. Each account uses its own signing
     * secret but the event handlers are the same — they branch on the
     * metadata `type` already, so they don't need to know which account
     * delivered the event.
     */
    protected function process(Request $request, ?string $webhookSecret)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

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
     * Handle a completed Stripe Checkout Session — fired for in-person
     * deposits and trip-booking deposits paid through Stripe Checkout.
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

        if ($type === 'in_person_deposit' && isset($metadata['purchase_request_id'])) {
            $this->handleInPersonDepositPaid($session, $metadata);
        }

        if ($type === 'trip_booking' && isset($metadata['shopping_trip_booking_id'])) {
            $this->handleTripBookingPaid($session, $metadata);
        }
    }

    /**
     * Trip booking deposit cleared.
     *
     * Uses a DB lock to handle the race condition where two customers pay for
     * the same trip simultaneously. The first one in wins and the trip is
     * closed immediately. The second one gets an automatic refund.
     */
    protected function handleTripBookingPaid($session, array $metadata)
    {
        $booking = \App\Models\ShoppingTripBooking::find($metadata['shopping_trip_booking_id']);

        if (! $booking) {
            Log::warning('ShoppingTripBooking not found for trip_booking webhook', [
                'id'         => $metadata['shopping_trip_booking_id'],
                'session_id' => $session->id,
            ]);
            return;
        }

        // Idempotency — webhook may fire more than once.
        if ($booking->status === \App\Models\ShoppingTripBooking::STATUS_CONFIRMED) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $session) {
            // Lock the trip row so only one webhook can claim it at a time.
            $trip = \App\Models\ShoppingTrip::lockForUpdate()->find($booking->shopping_trip_id);

            $alreadyConfirmed = \App\Models\ShoppingTripBooking::where('shopping_trip_id', $trip->id)
                ->where('status', \App\Models\ShoppingTripBooking::STATUS_CONFIRMED)
                ->where('id', '!=', $booking->id)
                ->exists();

            if ($alreadyConfirmed) {
                // Race condition — another booking beat this one to payment.
                // Cancel this booking and issue a full refund via Stripe.
                $booking->update(['status' => \App\Models\ShoppingTripBooking::STATUS_CANCELLED]);

                try {
                    $stripe = \App\Services\StripeAccount::main();
                    if (! empty($session->payment_intent)) {
                        $stripe->refunds->create(['payment_intent' => $session->payment_intent]);
                        Log::info('Auto-refunded duplicate trip booking payment', [
                            'booking_id'      => $booking->id,
                            'payment_intent'  => $session->payment_intent,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to auto-refund duplicate trip booking', [
                        'booking_id' => $booking->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

                return;
            }

            // First payment in — confirm the booking and close the trip so
            // no one else can book it from the date picker.
            $booking->update([
                'status'  => \App\Models\ShoppingTripBooking::STATUS_CONFIRMED,
                'paid_at' => now(),
            ]);

            $trip->update(['status' => \App\Models\ShoppingTrip::STATUS_CLOSED]);

            Log::info('Trip booking confirmed, trip closed', [
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'trip_id'        => $trip->id,
            ]);
        });

        // Email the customer their booking confirmation — only on the confirmed
        // path (not the auto-refunded race loser). Best-effort.
        if ($booking->status === \App\Models\ShoppingTripBooking::STATUS_CONFIRMED) {
            try {
                Mail::to($booking->user)->queue(new \App\Mail\ShoppingTripBookingConfirmed($booking));
            } catch (\Exception $e) {
                Log::error('Failed to queue trip booking confirmation email', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * In-person scheduling deposit cleared. PR was created in
     * awaiting_deposit when the customer hit Submit; now we flip it to
     * pending_review so Velonie sees it in her queue, and queue the
     * customer confirmation + team alert (deferred from submit so
     * tire-kicker bookings don't generate spam).
     */
    protected function handleInPersonDepositPaid($session, array $metadata)
    {
        $pr = PurchaseRequest::find($metadata['purchase_request_id']);
        if (! $pr) {
            Log::warning('Purchase request not found for in-person deposit completion', [
                'purchase_request_id' => $metadata['purchase_request_id'],
                'session_id'          => $session->id,
            ]);
            return;
        }

        // Idempotency — webhook can fire more than once (retries, manual
        // replays). If we already processed this deposit, no-op.
        if ($pr->depositPaid()) {
            return;
        }

        try {
            $pr->update([
                'status'           => PurchaseRequest::STATUS_PENDING_REVIEW,
                'deposit_paid_at'  => now(),
            ]);

            $pr->load(['items', 'user', 'shoppingTrip', 'stores']);

            try {
                \Illuminate\Support\Facades\Mail::to($pr->user)->queue(
                    new \App\Mail\PurchaseRequestInPersonScheduled($pr),
                );
            } catch (\Exception $e) {
                Log::error('Failed to queue in-person scheduled email', ['error' => $e->getMessage()]);
            }

            try {
                $teamEmails = \App\Models\User::query()
                    ->where('role', \App\Models\User::ROLE_EMPLOYEE)
                    ->where('team', \App\Models\User::TEAM_SHOPPING)
                    ->pluck('email')
                    ->all();
                if (! empty($teamEmails)) {
                    \Illuminate\Support\Facades\Mail::to($teamEmails)->queue(
                        new \App\Mail\PurchaseRequestCreatedTeamNotification($pr),
                    );
                }
            } catch (\Exception $e) {
                Log::error('Failed to queue in-person team notification', ['error' => $e->getMessage()]);
            }

            Log::info('In-person deposit cleared, PR confirmed', [
                'purchase_request_id' => $pr->id,
                'session_id'          => $session->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle in-person deposit paid', [
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