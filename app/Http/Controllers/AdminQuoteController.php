<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

class AdminQuoteController extends Controller
{
    public function markAsProcessing(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PACKAGES_COMPLETE) {
            return response()->json(['success' => false, 'message' => 'Order must have all packages complete'], 400);
        }
        if (!$order->allItemsArrived()) {
            return response()->json(['success' => false, 'message' => 'Not all packages arrived'], 400);
        }

        try {
            $order->markAsProcessing();
            return response()->json(['success' => true, 'message' => 'Order processing', 'data' => $order->fresh()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function prepareQuote(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_DELIVERED) {
            return response()->json(['success' => false, 'message' => 'Order must be delivered before preparing invoice'], 400);
        }

        $request->validate([
            'quote_items' => 'required|array|min:1',
            'quote_items.*.item' => 'required|string|max:100',
            'quote_items.*.description' => 'required|string|max:255',
            'quote_items.*.amount' => 'required|numeric|min:0',
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

            $order->update([
                'quote_breakdown' => $quoteBreakdown,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Quote prepared', 'data' => $order->fresh()]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendInvoice(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_DELIVERED) {
            return response()->json(['success' => false, 'message' => 'Order must be delivered'], 400);
        }

        if (!$order->box_price) {
             return response()->json(['success' => false, 'message' => 'Box price is missing from order'], 400);
        }

        DB::beginTransaction();

        try {
            $user = $order->user;
            $stripe = Cashier::stripe();

            $depositPaid = $order->deposit_amount ?? 0;
            $remainingBoxBalance = $order->box_price - $depositPaid;
            
            $extrasTotal = 0;
            if ($order->quote_breakdown) {
                $extrasTotal = collect($order->quote_breakdown)->sum('amount');
            }

            $totalFinalInvoice = $remainingBoxBalance + $extrasTotal;

            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 7,
                'description' => "Final Balance - Order {$order->order_number}",
                'metadata' => [
                    'type' => 'final_invoice',
                    'order_id' => (string)$order->id,
                    'order_number' => $order->order_number,
                    'admin_id' => (string)$request->user()->id,
                ],
                'auto_advance' => false,
            ]);

            if ($remainingBoxBalance > 0) {
                $stripe->invoiceItems->create([
                    'customer' => $user->stripe_id,
                    'invoice' => $stripeInvoice->id,
                    'amount' => intval($remainingBoxBalance * 100),
                    'currency' => 'mxn',
                    'description' => "Remaining 50% Balance for Shipment (Total: \${$order->box_price}, Paid Deposit: \${$depositPaid})",
                ]);
            }

            if ($order->quote_breakdown) {
                foreach ($order->quote_breakdown as $item) {
                    $stripe->invoiceItems->create([
                        'customer' => $user->stripe_id,
                        'invoice' => $stripeInvoice->id,
                        'amount' => intval($item['amount'] * 100),
                        'currency' => 'mxn',
                        'description' => $item['description'],
                    ]);
                }
            }

            // FIX: Capture the updated invoice object
            $finalizedInvoice = $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);
            
            // Use the URL from the sent invoice object
            $paymentLink = $sentInvoice->hosted_invoice_url;

            $order->update([
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'stripe_invoice_id' => $stripeInvoice->id,
                'payment_link' => $paymentLink,
                'quoted_amount' => $totalFinalInvoice,
                'quote_sent_at' => now(),
                'quote_expires_at' => now()->addDays(7),
            ]);

            DB::commit();

            Log::info('Final invoice sent', [
                'order_id' => $order->id, 
                'total' => $totalFinalInvoice,
                'link' => $paymentLink
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Final invoice sent successfully',
                'data' => [
                    'order' => $order->fresh(),
                    'payment_link' => $paymentLink,
                    'total_due' => $totalFinalInvoice
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send final invoice', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function resendInvoice(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_AWAITING_PAYMENT || !$order->stripe_invoice_id) {
            return response()->json(['success' => false, 'message' => 'No invoice to resend'], 400);
        }
        try {
            // If link is missing, try to retrieve it
            if (!$order->payment_link) {
                 $invoice = Cashier::stripe()->invoices->retrieve($order->stripe_invoice_id);
                 if ($invoice && $invoice->hosted_invoice_url) {
                     $order->update(['payment_link' => $invoice->hosted_invoice_url]);
                 }
            }

            Cashier::stripe()->invoices->sendInvoice($order->stripe_invoice_id);
            return response()->json(['success' => true, 'message' => 'Invoice resent']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cancelInvoice(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel'], 400);
        }
        try {
            if ($order->stripe_invoice_id) {
                Cashier::stripe()->invoices->voidInvoice($order->stripe_invoice_id);
            }
            $order->update([
                'status' => Order::STATUS_DELIVERED,
                'stripe_invoice_id' => null,
                'payment_link' => null,
                'quote_sent_at' => null,
            ]);
            return response()->json(['success' => true, 'message' => 'Invoice cancelled']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function ordersReadyForQuote(Request $request)
    {
        $query = Order::with(['user', 'items'])
            ->where('status', Order::STATUS_DELIVERED)
            ->whereNull('quote_sent_at');
            
        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }
}