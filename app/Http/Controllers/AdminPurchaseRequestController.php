<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Mail\PurchaseRequestQuoteSent;
use App\Mail\PurchaseRequestItemsPurchased; // Import new mailable
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class AdminPurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseRequest::with(['user', 'items']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate(20)
        ]);
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        return response()->json([
            'success' => true,
            'data' => $purchaseRequest->load(['user', 'items'])
        ]);
    }

    /**
     * Update purchase request details (Admin Manual Override)
     * NOTE: This does NOT trigger emails or Stripe actions. Pure DB update.
     */
    public function update(Request $request, PurchaseRequest $purchaseRequest)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending_review,quoted,paid,purchased,rejected,cancelled',
            'items_total' => 'nullable|numeric',
            'shipping_cost' => 'nullable|numeric',
            'sales_tax' => 'nullable|numeric',
            'processing_fee' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'admin_notes' => 'nullable|string',
            'payment_link' => 'nullable|url',
        ]);

        // Direct database update - No Mailable invoked here
        $purchaseRequest->update($validated);

        Log::info('Admin manually updated purchase request', [
            'id' => $purchaseRequest->id,
            'admin_id' => $request->user()->id,
            'changes' => $purchaseRequest->getChanges()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase request updated successfully',
            'data' => $purchaseRequest->load(['user', 'items'])
        ]);
    }

    /**
     * Delete a purchase request
     */
    public function destroy(PurchaseRequest $purchaseRequest)
    {
        DB::beginTransaction();

        try {
            // 1. Void Invoice if it exists and hasn't been paid
            if ($purchaseRequest->stripe_invoice_id && $purchaseRequest->status !== PurchaseRequest::STATUS_PAID) {
                try {
                    $stripe = Cashier::stripe();
                    $invoice = $stripe->invoices->retrieve($purchaseRequest->stripe_invoice_id);
                    if ($invoice->status === 'open') {
                        $stripe->invoices->voidInvoice($purchaseRequest->stripe_invoice_id);
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not void invoice during deletion', ['id' => $purchaseRequest->stripe_invoice_id]);
                }
            }

            // 2. Delete the record (Cascade will handle items)
            $purchaseRequest->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase request deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete purchase request', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete request'], 500);
        }
    }

    /**
     * Bulk delete purchase requests
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:purchase_requests,id',
        ]);

        DB::beginTransaction();

        try {
            $requests = PurchaseRequest::whereIn('id', $request->ids)->get();
            $deletedCount = 0;

            foreach ($requests as $pr) {
                // Void invoice if exists and not paid
                if ($pr->stripe_invoice_id && $pr->status !== PurchaseRequest::STATUS_PAID) {
                    try {
                        $stripe = Cashier::stripe();
                        $invoice = $stripe->invoices->retrieve($pr->stripe_invoice_id);
                        if ($invoice->status === 'open') {
                            $stripe->invoices->voidInvoice($pr->stripe_invoice_id);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Could not void invoice during bulk deletion', ['id' => $pr->stripe_invoice_id]);
                    }
                }

                $pr->delete();
                $deletedCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$deletedCount} requests deleted successfully"
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk delete purchase requests', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete requests'], 500);
        }
    }

    public function createQuote(Request $request, PurchaseRequest $purchaseRequest)
    {
        $request->validate([
            'items_total' => 'required|numeric|min:0',
            'shipping_cost' => 'required|numeric|min:0', 
            'sales_tax' => 'required|numeric|min:0', 
            'admin_notes' => 'nullable|string'
        ]);

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json(['success' => false, 'message' => 'Request is not in pending review state'], 400);
        }

        DB::beginTransaction();

        try {
            // 1. Calculate Totals in USD
            $subtotalUsd = floatval($request->items_total) + floatval($request->shipping_cost) + floatval($request->sales_tax);
            
            // 2. Apply 8% Markup
            $markupPercentage = 0.08;
            $feeUsd = round($subtotalUsd * $markupPercentage, 2);
            $totalUsd = $subtotalUsd + $feeUsd;

            // 3. Convert to MXN
            $exchangeRate = 18.00;
            $subtotalMxn = round($subtotalUsd * $exchangeRate, 2);
            $feeMxn = round($feeUsd * $exchangeRate, 2);

            // 4. Create Stripe Invoice (MXN)
            $user = $purchaseRequest->user;
            if (!$user->stripe_id) {
                $user->createAsStripeCustomer();
            }
            
            $stripe = Cashier::stripe();

            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'currency' => 'mxn',
                'collection_method' => 'send_invoice',
                'days_until_due' => 3, 
                'description' => "Assisted Purchase Request: {$purchaseRequest->request_number}",
                'metadata' => [
                    'type' => 'purchase_request_invoice',
                    'purchase_request_id' => $purchaseRequest->id,
                    'request_number' => $purchaseRequest->request_number,
                ],
                'auto_advance' => false,
            ]);

            // Cost of Goods Item
            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($subtotalMxn * 100),
                'currency' => 'mxn',
                'description' => "Cost of Goods (Products, Shipping & Tax) - \${$subtotalUsd} USD @ {$exchangeRate} MXN/USD",
            ]);

            // Service Fee Item
            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($feeMxn * 100),
                'currency' => 'mxn',
                'description' => "Service Fee (8%) - \${$feeUsd} USD @ {$exchangeRate} MXN/USD",
            ]);

            // Finalize & Send
            $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            // 5. Update Model
            $purchaseRequest->update([
                'items_total' => $request->items_total,
                'shipping_cost' => $request->shipping_cost,
                'sales_tax' => $request->sales_tax,
                'processing_fee' => $feeUsd,
                'total_amount' => $totalUsd,
                'status' => PurchaseRequest::STATUS_QUOTED,
                'stripe_invoice_id' => $stripeInvoice->id,
                'payment_link' => $sentInvoice->hosted_invoice_url,
                'quote_sent_at' => now(),
                'admin_notes' => $request->admin_notes,
            ]);

            DB::commit();

            // 6. Send Email
            try {
                Mail::to($user)->queue(new PurchaseRequestQuoteSent($purchaseRequest));
                Log::info('Quote email queued for ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue quote email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Quote created and invoice sent to customer (in MXN)',
                'data' => $purchaseRequest->load(['items', 'user']) 
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to quote purchase request', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function markAsPurchased(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PAID) {
            return response()->json([
                'success' => false, 
                'message' => 'Request must be paid before purchasing. Current status: ' . $purchaseRequest->status
            ], 400);
        }

        DB::beginTransaction();

        try {
            $user = $purchaseRequest->user;

            // ALWAYS create a new dedicated order for this purchase request
            // We skip 'collecting' and go straight to 'awaiting_packages'
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_AWAITING_PACKAGES,
                'delivery_address' => $user->address, // Use user's default profile address
                'is_rural' => false, // Admin can adjust later if needed
                'currency' => 'mxn',
                'completed_at' => now(), // Mark as "completed" immediately
            ]);

            // Convert PurchaseRequest Items to Order Items
            foreach ($purchaseRequest->items as $prItem) {
                $orderItem = new OrderItem([
                    'order_id' => $order->id,
                    'product_name' => $prItem->product_name,
                    'product_url' => $prItem->product_url,
                    'product_image_url' => $prItem->product_image_url,
                    'quantity' => $prItem->quantity,
                    'declared_value' => $prItem->price, // Use the price paid as declared value
                    'purchase_request_item_id' => $prItem->id,
                    'is_assisted_purchase' => true,
                    // Note: Tracking number will be null initially. 
                    // Admin will update this specific order item when the vendor sends tracking.
                ]);
                $orderItem->save();
            }

            // Update Request Status
            $purchaseRequest->update([
                'status' => PurchaseRequest::STATUS_PURCHASED,
                'purchased_at' => now(),
            ]);

            // Calculate totals for the new order
            $order->update([
                'declared_value' => $order->calculateTotalDeclaredValue(),
                'iva_amount' => $order->calculateIVA()
            ]);

            DB::commit();

            // Send Notification Email
            try {
                Mail::to($user)->queue(new PurchaseRequestItemsPurchased($purchaseRequest, $order));
                Log::info('Items purchased email queued for ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue items purchased email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Items purchased and new order created',
                'data' => [
                    'purchase_request' => $purchaseRequest->load(['items', 'user']),
                    'target_order_number' => $order->order_number
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark purchase request as purchased', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest)
    {
        $request->validate(['reason' => 'required|string']);

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'admin_notes' => $request->reason
        ]);

        if ($purchaseRequest->stripe_invoice_id) {
            try {
                Cashier::stripe()->invoices->voidInvoice($purchaseRequest->stripe_invoice_id);
            } catch (\Exception $e) {
                Log::warning('Could not void invoice on rejection', ['id' => $purchaseRequest->stripe_invoice_id]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Request rejected']);
    }
}