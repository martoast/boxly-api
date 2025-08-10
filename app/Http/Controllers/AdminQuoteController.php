<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class AdminQuoteController extends Controller
{
    /**
     * Mark order as processing (when all packages have arrived)
     */
    public function markAsProcessing(Request $request, Order $order)
    {
        // Validate order status
        if ($order->status !== Order::STATUS_PACKAGES_COMPLETE) {
            return response()->json([
                'success' => false,
                'message' => 'Order must have all packages complete before processing'
            ], 400);
        }

        // Validate all items have arrived and been weighed
        if (!$order->allItemsArrived()) {
            return response()->json([
                'success' => false,
                'message' => 'Not all packages have arrived yet'
            ], 400);
        }

        if (!$order->allItemsWeighed()) {
            return response()->json([
                'success' => false,
                'message' => 'Not all packages have been weighed yet'
            ], 400);
        }

        try {
            $order->markAsProcessing();

            Log::info('Order marked as processing', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order marked as processing',
                'data' => $order->fresh()->load(['user', 'items'])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark order as processing', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Calculate and prepare quote for an order
     * Admin provides complete custom quote
     */
    public function prepareQuote(Request $request, Order $order)
    {
        // Validate order status
        if (!in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_PACKAGES_COMPLETE])) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be in processing or packages complete status to prepare quote'
            ], 400);
        }

        $request->validate([
            'quote_items' => 'required|array|min:1',
            'quote_items.*.item' => 'required|string|max:100',
            'quote_items.*.description' => 'required|string|max:255',
            'quote_items.*.amount' => 'required|numeric|min:0|max:99999',
            'quote_items.*.type' => 'nullable|string|in:box,shipping,handling,tax,surcharge,insurance,custom,discount',
        ]);

        DB::beginTransaction();

        try {
            // If not in processing, mark it as processing first
            if ($order->status === Order::STATUS_PACKAGES_COMPLETE) {
                $order->markAsProcessing();
            }

            $quoteBreakdown = [];

            // Build quote breakdown from provided items
            foreach ($request->quote_items as $item) {
                $quoteBreakdown[] = [
                    'item' => $item['item'],
                    'description' => $item['description'],
                    'amount' => floatval($item['amount']),
                    'currency' => 'MXN',
                    'type' => $item['type'] ?? 'custom'
                ];
            }

            // Calculate total
            $totalAmount = array_sum(array_column($quoteBreakdown, 'amount'));

            // Update order with quote details
            $order->update([
                'quote_breakdown' => $quoteBreakdown,
                'quoted_amount' => $totalAmount,
            ]);

            DB::commit();

            Log::info('Quote prepared for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_amount' => $totalAmount,
                'quote_breakdown' => $quoteBreakdown,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Quote prepared successfully',
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items']),
                    'quote' => [
                        'breakdown' => $quoteBreakdown,
                        'total' => $totalAmount,
                        'currency' => 'MXN',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to prepare quote', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to prepare quote',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Send quote to customer with payment link using Stripe Invoice
     */
    public function sendQuote(Request $request, Order $order)
    {
        $request->validate([
            'send_copy_to_admin' => 'boolean',
        ]);

        // Validate order status
        if (!in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_QUOTE_SENT])) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be in processing status to send quote'
            ], 400);
        }

        // Validate quote is prepared
        if (!$order->quote_breakdown || !$order->quoted_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Quote must be prepared before sending'
            ], 400);
        }

        Log::info('Preparing to send quote with invoice', [
            'order_id' => $order->id,
            'quote_breakdown' => $order->quote_breakdown,
            'quoted_amount' => $order->quoted_amount,
        ]);

        DB::beginTransaction();

        try {
            $user = $order->user;
            $stripe = Cashier::stripe(); // This gets the Stripe client from Cashier

            // Calculate total from quote breakdown
            $total = collect($order->quote_breakdown)->sum('amount');

            // Create invoice directly with Stripe API
            // We use 'exclude' to avoid any pending items from other orders
            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 7,
                'description' => 'Orden ' . $order->order_number . ' - Servicio de Consolidación y Envío',
                'metadata' => [
                    'order_id' => (string)$order->id,
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->tracking_number,
                    'type' => 'order_quote',
                    'admin_id' => (string)$request->user()->id,
                ],
                'pending_invoice_items_behavior' => 'exclude', // This ensures no pending items are included
                'auto_advance' => false, // We'll manually finalize it
            ]);

            // Add the invoice item specifically for this order
            // By specifying the invoice ID, it goes directly to this invoice
            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id, // This attaches it directly to our invoice
                'amount' => intval($total * 100), // Convert to cents
                'currency' => 'mxn',
                'description' => 'Orden ' . $order->order_number . ' - Servicio de Consolidación y Envío',
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            ]);

            // Finalize the invoice (makes it ready to be sent)
            $stripeInvoice = $stripe->invoices->finalizeInvoice($stripeInvoice->id);

            // Send the invoice email to the customer
            $stripeInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            // Get the payment link
            $paymentLink = $stripeInvoice->hosted_invoice_url;

            Log::info('Invoice created and sent', [
                'invoice_id' => $stripeInvoice->id,
                'status' => $stripeInvoice->status,
                'payment_link' => $paymentLink,
                'total' => $stripeInvoice->total,
            ]);

            // Update order with invoice details
            $order->update([
                'status' => Order::STATUS_QUOTE_SENT,
                'stripe_invoice_id' => $stripeInvoice->id,
                'payment_link' => $paymentLink,
                'quote_sent_at' => now(),
                'quote_expires_at' => now()->addDays(7),
            ]);

            DB::commit();

            Log::info('Quote sent successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_id' => $stripeInvoice->id,
                'amount' => $total,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Quote sent to customer successfully',
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items']),
                    'payment_link' => $paymentLink,
                    'expires_at' => $order->quote_expires_at->format('Y-m-d H:i:s'),
                    'invoice_id' => $stripeInvoice->id,
                ]
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            DB::rollBack();

            Log::error('Stripe API error when sending quote', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'stripe_error' => $e->getStripeCode(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Stripe error: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to send quote', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send quote: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Resend quote email to customer
     */
    public function resendQuote(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_QUOTE_SENT) {
            return response()->json([
                'success' => false,
                'message' => 'Order must have a quote sent to resend'
            ], 400);
        }

        if (!$order->payment_link || !$order->stripe_invoice_id) {
            return response()->json([
                'success' => false,
                'message' => 'No payment link available for this order'
            ], 400);
        }

        try {
            $stripe = Cashier::stripe();

            // Check if quote has expired and extend if needed
            if ($order->isQuoteExpired()) {
                // Update the invoice due date
                $stripe->invoices->update($order->stripe_invoice_id, [
                    'due_date' => now()->addDays(7)->timestamp,
                ]);

                // Update order expiration
                $order->update([
                    'quote_expires_at' => now()->addDays(7),
                ]);

                Log::info('Quote expiration extended', [
                    'order_id' => $order->id,
                    'new_expiration' => $order->quote_expires_at,
                ]);
            }

            // Resend the invoice email
            $stripe->invoices->sendInvoice($order->stripe_invoice_id);

            Log::info('Quote resent successfully', [
                'order_id' => $order->id,
                'user_email' => $order->user->email,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Quote email resent successfully',
                'data' => [
                    'payment_link' => $order->payment_link,
                    'expires_at' => $order->quote_expires_at->format('Y-m-d H:i:s'),
                    'was_extended' => $order->wasRecentlyUpdated,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend quote', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resend quote',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Cancel a quote and void the invoice
     */
    public function cancelQuote(Request $request, Order $order)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (!in_array($order->status, [Order::STATUS_QUOTE_SENT])) {
            return response()->json([
                'success' => false,
                'message' => 'Can only cancel quotes that have been sent'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Void the Stripe invoice if it exists
            if ($order->stripe_invoice_id) {
                $stripe = Cashier::stripe();
                $stripe->invoices->voidInvoice($order->stripe_invoice_id);

                Log::info('Stripe invoice voided', [
                    'invoice_id' => $order->stripe_invoice_id,
                    'order_id' => $order->id,
                ]);
            }

            // Reset order to processing status
            $order->update([
                'status' => Order::STATUS_PROCESSING,
                'stripe_invoice_id' => null,
                'payment_link' => null,
                'quote_sent_at' => null,
                'quote_expires_at' => null,
            ]);

            DB::commit();

            Log::info('Quote cancelled successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $request->reason,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Quote cancelled successfully',
                'data' => $order->fresh()->load(['user', 'items'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to cancel quote', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel quote',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get orders ready for quote preparation
     */
    public function ordersReadyForQuote(Request $request)
    {
        $query = Order::with(['user', 'items'])
            ->whereIn('status', [Order::STATUS_PACKAGES_COMPLETE, Order::STATUS_PROCESSING])
            ->whereNull('quote_sent_at');

        // Filter by search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->oldest('completed_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}
