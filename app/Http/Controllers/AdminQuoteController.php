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
    public function markAsProcessing(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PACKAGES_COMPLETE) {
            return response()->json([
                'success' => false,
                'message' => 'Order must have all packages complete before processing'
            ], 400);
        }

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
     * Prepare quote for a delivered order
     * This allows admin to prepare the invoice breakdown before sending it to customer
     */
    public function prepareQuote(Request $request, Order $order)
    {
        // Quote can only be prepared after order is delivered
        if ($order->status !== Order::STATUS_DELIVERED) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be delivered before preparing invoice'
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
            $quoteBreakdown = [];

            foreach ($request->quote_items as $item) {
                $quoteBreakdown[] = [
                    'item' => $item['item'],
                    'description' => $item['description'],
                    'amount' => floatval($item['amount']),
                    'currency' => 'MXN',
                    'type' => $item['type'] ?? 'custom'
                ];
            }

            $totalAmount = array_sum(array_column($quoteBreakdown, 'amount'));

            // Just save the quote breakdown, don't change status
            // Status changes to awaiting_payment when invoice is actually sent
            $order->update([
                'quote_breakdown' => $quoteBreakdown,
                'quoted_amount' => $totalAmount,
            ]);

            DB::commit();

            Log::info('Quote prepared for delivered order', [
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
     * Send invoice to customer after delivery
     * This is now called "sendInvoice" but frontend may still call it "send-quote"
     */
    public function sendInvoice(Request $request, Order $order)
    {
        $request->validate([
            'send_copy_to_admin' => 'boolean',
        ]);

        if ($order->status !== Order::STATUS_DELIVERED) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be delivered to send the final invoice'
            ], 400);
        }

        if (!$order->quote_breakdown || !$order->quoted_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Quote must be prepared before sending invoice'
            ], 400);
        }

        Log::info('Preparing to send invoice post-delivery', [
            'order_id' => $order->id,
            'quote_breakdown' => $order->quote_breakdown,
            'quoted_amount' => $order->quoted_amount,
        ]);

        DB::beginTransaction();

        try {
            $user = $order->user;
            $stripe = Cashier::stripe();

            $total = collect($order->quote_breakdown)->sum('amount');

            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 7,
                'description' => 'Orden ' . $order->order_number . ' - Servicio de Consolidación y Envío',
                'metadata' => [
                    'order_id' => (string)$order->id,
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->tracking_number,
                    'type' => 'order_invoice',
                    'admin_id' => (string)$request->user()->id,
                ],
                'pending_invoice_items_behavior' => 'exclude',
                'auto_advance' => false,
            ]);

            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($total * 100),
                'currency' => 'mxn',
                'description' => 'Orden ' . $order->order_number . ' - Servicio de Consolidación y Envío',
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            ]);

            $stripeInvoice = $stripe->invoices->finalizeInvoice($stripeInvoice->id);

            $stripeInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            $paymentLink = $stripeInvoice->hosted_invoice_url;

            Log::info('Invoice created and sent post-delivery', [
                'invoice_id' => $stripeInvoice->id,
                'status' => $stripeInvoice->status,
                'payment_link' => $paymentLink,
                'total' => $stripeInvoice->total,
            ]);

            $order->update([
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'stripe_invoice_id' => $stripeInvoice->id,
                'payment_link' => $paymentLink,
                'quote_sent_at' => now(),
                'quote_expires_at' => now()->addDays(7),
            ]);

            DB::commit();

            Log::info('Invoice sent successfully post-delivery', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_id' => $stripeInvoice->id,
                'amount' => $total,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice sent to customer successfully',
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items']),
                    'payment_link' => $paymentLink,
                    'expires_at' => $order->quote_expires_at->format('Y-m-d H:i:s'),
                    'invoice_id' => $stripeInvoice->id,
                ]
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            DB::rollBack();

            Log::error('Stripe API error when sending invoice', [
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

            Log::error('Failed to send invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send invoice: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function resendInvoice(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
            return response()->json([
                'success' => false,
                'message' => 'Order must have an invoice sent to resend'
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

            if ($order->isQuoteExpired()) {
                $stripe->invoices->update($order->stripe_invoice_id, [
                    'due_date' => now()->addDays(7)->timestamp,
                ]);

                $order->update([
                    'quote_expires_at' => now()->addDays(7),
                ]);

                Log::info('Invoice expiration extended', [
                    'order_id' => $order->id,
                    'new_expiration' => $order->quote_expires_at,
                ]);
            }

            $stripe->invoices->sendInvoice($order->stripe_invoice_id);

            Log::info('Invoice resent successfully', [
                'order_id' => $order->id,
                'user_email' => $order->user->email,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice email resent successfully',
                'data' => [
                    'payment_link' => $order->payment_link,
                    'expires_at' => $order->quote_expires_at->format('Y-m-d H:i:s'),
                    'was_extended' => $order->wasRecentlyUpdated,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resend invoice',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function cancelInvoice(Request $request, Order $order)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
            return response()->json([
                'success' => false,
                'message' => 'Can only cancel invoices that have been sent'
            ], 400);
        }

        DB::beginTransaction();

        try {
            if ($order->stripe_invoice_id) {
                $stripe = Cashier::stripe();
                $stripe->invoices->voidInvoice($order->stripe_invoice_id);

                Log::info('Stripe invoice voided', [
                    'invoice_id' => $order->stripe_invoice_id,
                    'order_id' => $order->id,
                ]);
            }

            // Return to delivered status when invoice is cancelled
            $order->update([
                'status' => Order::STATUS_DELIVERED,
                'stripe_invoice_id' => null,
                'payment_link' => null,
                'quote_sent_at' => null,
                'quote_expires_at' => null,
            ]);

            DB::commit();

            Log::info('Invoice cancelled successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $request->reason,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice cancelled successfully',
                'data' => $order->fresh()->load(['user', 'items'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to cancel invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel invoice',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get orders ready for invoice (delivered orders without invoice sent)
     */
    public function ordersReadyForQuote(Request $request)
    {
        $query = Order::with(['user', 'items'])
            ->where('status', Order::STATUS_DELIVERED)
            ->whereNull('quote_sent_at');

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

        $orders = $query->latest('delivered_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}