<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Mail\PurchaseRequestQuoteSent;
use App\Mail\PurchaseRequestItemsPurchased;
// Removed PurchaseRequestCreated import to prevent notification on admin create
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Create a new purchase request (Admin Manual Entry)
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_url' => 'required|string|max:2000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'status' => 'nullable|in:pending_review,quoted,paid,purchased',
            'admin_notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = User::findOrFail($request->user_id);

            // 1. Create the Request Ticket
            $pr = PurchaseRequest::create([
                'user_id' => $user->id,
                'request_number' => PurchaseRequest::generateRequestNumber(),
                'status' => $request->status ?? PurchaseRequest::STATUS_PENDING_REVIEW,
                'currency' => 'usd',
                'admin_notes' => $request->admin_notes,
            ]);

            // 2. Process Items
            $itemsInput = $request->input('items');

            foreach ($itemsInput as $index => $itemData) {
                
                // Handle options parsing
                $options = null;
                if (isset($itemData['options'])) {
                    $options = is_string($itemData['options']) 
                        ? json_decode($itemData['options'], true) 
                        : $itemData['options'];
                }

                // Create Item Record
                $item = PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'product_name' => $itemData['product_name'],
                    'product_url' => $itemData['product_url'],
                    'price' => $itemData['price'],
                    'quantity' => $itemData['quantity'],
                    'options' => $options,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // 3. Handle Image Upload
                if ($request->hasFile("items.{$index}.image")) {
                    $file = $request->file("items.{$index}.image");
                    
                    $userName = Str::slug($user->name);
                    $storagePath = "users/{$userName}-{$user->id}/requests/{$pr->request_number}/items/{$item->id}";
                    
                    $filename = "image-" . time() . "." . $file->getClientOriginalExtension();
                    
                    $path = Storage::disk('spaces')->putFileAs(
                        $storagePath,
                        $file,
                        $filename,
                        'public'
                    );
                    
                    $url = config('filesystems.disks.spaces.url') . '/' . $path;
                    
                    $item->update([
                        'image_path' => $path,
                        'image_filename' => $file->getClientOriginalName(),
                        'image_mime_type' => $file->getClientMimeType(),
                        'image_size' => $file->getSize(),
                        'image_url' => $url,
                    ]);
                }
            }

            DB::commit();

            Log::info('Admin created Purchase Request (No email sent)', [
                'id' => $pr->id, 
                'admin_id' => $request->user()->id,
                'customer_id' => $user->id
            ]);

            // NOTE: Intentionally NOT sending PurchaseRequestCreated email here
            // to allow admins to backfill data without spamming users.

            return response()->json([
                'success' => true,
                'message' => 'Purchase Request created successfully.',
                'data' => $pr->load('items')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin Purchase Request Create Failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create request',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update purchase request details (Admin Manual Override)
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

    public function destroy(PurchaseRequest $purchaseRequest)
    {
        DB::beginTransaction();

        try {
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

            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($subtotalMxn * 100),
                'currency' => 'mxn',
                'description' => "Cost of Goods (Products, Shipping & Tax) - \${$subtotalUsd} USD @ {$exchangeRate} MXN/USD",
            ]);

            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($feeMxn * 100),
                'currency' => 'mxn',
                'description' => "Service Fee (8%) - \${$feeUsd} USD @ {$exchangeRate} MXN/USD",
            ]);

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

            // Create new order (awaiting_packages)
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_AWAITING_PACKAGES,
                'delivery_address' => $user->address,
                'is_rural' => false,
                'currency' => 'mxn',
                'completed_at' => now(),
            ]);

            // Convert Items
            foreach ($purchaseRequest->items as $prItem) {
                
                // Logic to get the best available image URL
                $imageUrl = null;
                if ($prItem->image_url) {
                    // If we have a file upload URL, use it
                    $imageUrl = $prItem->image_full_url; 
                } elseif ($prItem->product_image_url) {
                    // Fallback to original scraped/provided URL
                    $imageUrl = $prItem->product_image_url;
                }

                $orderItem = new OrderItem([
                    'order_id' => $order->id,
                    'product_name' => $prItem->product_name,
                    'product_url' => $prItem->product_url,
                    
                    // CRITICAL FIX: Map the image URL here
                    'product_image_url' => $imageUrl,
                    
                    'quantity' => $prItem->quantity,
                    'declared_value' => $prItem->price,
                    'purchase_request_item_id' => $prItem->id,
                    'is_assisted_purchase' => true,
                ]);
                
                $orderItem->save();
            }

            // Update Request Status
            $purchaseRequest->update([
                'status' => PurchaseRequest::STATUS_PURCHASED,
                'purchased_at' => now(),
            ]);

            // Calculate totals
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