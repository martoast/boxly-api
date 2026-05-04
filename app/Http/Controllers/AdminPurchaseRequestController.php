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
use App\Services\StripeAccount;

class AdminPurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseRequest::with(['user', 'items']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('source')) {
            // 'store' or 'assisted' — lets Velonie filter to her store queue
            $query->where('source', $request->source);
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

    /**
     * Per-item stock-status update (store-source PRs).
     *
     * Velonie hits this from the PR detail page for each line. Items marked
     * `unavailable` stay on the PR (so the customer can see what was out of
     * stock) but get excluded from the Stripe invoice when createQuote runs.
     * Once the PR is quoted/paid/purchased, item stock state is locked.
     */
    public function updateItemStockStatus(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestItem $item)
    {
        if ($item->purchase_request_id !== $purchaseRequest->id) {
            return response()->json(['success' => false, 'message' => 'Item does not belong to this PR'], 404);
        }

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json([
                'success' => false,
                'message' => 'Stock status can only be changed while the PR is in pending_review',
            ], 400);
        }

        $validated = $request->validate([
            'stock_status' => 'required|in:unverified,available,unavailable',
        ]);

        $item->update([
            'stock_status'     => $validated['stock_status'],
            'stock_checked_at' => $validated['stock_status'] === PurchaseRequestItem::STOCK_UNVERIFIED ? null : now(),
            'stock_checked_by' => $validated['stock_status'] === PurchaseRequestItem::STOCK_UNVERIFIED ? null : $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $item->fresh(),
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
            'currency' => 'nullable|in:usd,mxn',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_url' => 'required|string|max:2000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'status' => 'nullable|in:pending_review,quoted,paid,purchased',
            'payment_method' => 'nullable|in:stripe,manual_deposit',
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
                'currency' => $request->input('currency', 'usd'),
                'payment_method' => $request->payment_method ?? PurchaseRequest::PAYMENT_METHOD_STRIPE,
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
            'currency' => 'nullable|in:usd,mxn',
            'payment_method' => 'nullable|in:stripe,manual_deposit',
            'items_total' => 'nullable|numeric',
            'shipping_cost' => 'nullable|numeric',
            'sales_tax' => 'nullable|numeric',
            'processing_fee' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'admin_notes' => 'nullable|string',
            'payment_link' => 'nullable|url',
        ]);

        // Auto-calculate processing_fee as the difference between total_amount and items_total
        if (isset($validated['items_total']) && isset($validated['total_amount'])) {
            $validated['processing_fee'] = $validated['total_amount'] - $validated['items_total'];
        }

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
                    $stripe = $purchaseRequest->stripeClient();
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

    /**
     * Bulk-update the status of multiple purchase requests.
     *
     * For `purchased`, runs the same processPurchase() logic per PR — creates
     * a follow-up Order in awaiting_packages, mails the customer. PRs not in
     * `paid` state are skipped (returned in `skipped`). For all other
     * statuses, a flat status flip is fine.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'required|integer|exists:purchase_requests,id',
            'status' => 'required|in:pending_review,quoted,paid,purchased,rejected,cancelled',
        ]);

        $newStatus = $request->input('status');
        $ids = $request->input('ids');

        // `purchased` needs the per-PR Order-creation flow.
        if ($newStatus === PurchaseRequest::STATUS_PURCHASED) {
            $prs = PurchaseRequest::with('items', 'user')->whereIn('id', $ids)->get();

            $processed = [];
            $skipped = [];

            foreach ($prs as $pr) {
                if ($pr->status !== PurchaseRequest::STATUS_PAID) {
                    $skipped[] = ['id' => $pr->id, 'reason' => "not paid (was {$pr->status})"];
                    continue;
                }

                DB::beginTransaction();
                try {
                    $order = $this->processPurchase($pr);
                    DB::commit();

                    // Bulk does NOT email customers — admin housekeeping should be silent.
                    // Single-PR markAsPurchased still queues PurchaseRequestItemsPurchased.

                    $processed[] = ['id' => $pr->id, 'order_number' => $order->order_number];
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Bulk markAsPurchased failed for PR', ['pr_id' => $pr->id, 'error' => $e->getMessage()]);
                    $skipped[] = ['id' => $pr->id, 'reason' => 'error: ' . $e->getMessage()];
                }
            }

            Log::info('Bulk markAsPurchased complete', [
                'processed' => count($processed),
                'skipped'   => count($skipped),
                'admin_id'  => $request->user()->id,
            ]);

            $msg = count($processed) . " marcadas como compradas";
            if (count($skipped) > 0) {
                $msg .= " · " . count($skipped) . " omitidas (no estaban pagadas)";
            }

            return response()->json([
                'success'   => true,
                'message'   => $msg,
                'count'     => count($processed),
                'processed' => $processed,
                'skipped'   => $skipped,
            ]);
        }

        // Flat status flip for the simple transitions.
        DB::beginTransaction();
        try {
            $updates = ['status' => $newStatus];
            if ($newStatus === PurchaseRequest::STATUS_PAID) {
                $updates['paid_at'] = now();
            }

            $count = PurchaseRequest::whereIn('id', $ids)->update($updates);

            DB::commit();

            Log::info('Bulk-updated purchase request status', [
                'ids'      => $ids,
                'status'   => $newStatus,
                'count'    => $count,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$count} requests updated to {$newStatus}",
                'count'   => $count,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk-update purchase request status', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update requests: ' . $e->getMessage(),
            ], 500);
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
                        $stripe = $pr->stripeClient();
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

    /**
     * Merge multiple purchase requests from the same user into one.
     * The request with the furthest status becomes the target.
     * All items from source requests are moved to the target, then source requests are deleted.
     */
    public function mergePurchaseRequests(Request $request)
    {
        $request->validate([
            'request_ids' => 'required|array|min:2',
            'request_ids.*' => 'required|integer|exists:purchase_requests,id',
        ]);

        $purchaseRequests = PurchaseRequest::whereIn('id', $request->request_ids)
            ->with(['items', 'user'])
            ->get();

        if ($purchaseRequests->count() < 2) {
            return response()->json([
                'success' => false,
                'message' => 'At least 2 valid purchase requests are required to merge'
            ], 400);
        }

        // Validate all requests belong to the same user
        $userIds = $purchaseRequests->pluck('user_id')->unique();
        if ($userIds->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'All purchase requests must belong to the same user'
            ], 400);
        }

        // Status priority - furthest along wins (higher number = further along)
        $statusPriority = [
            PurchaseRequest::STATUS_PENDING_REVIEW => 0,
            PurchaseRequest::STATUS_QUOTED => 1,
            PurchaseRequest::STATUS_PAID => 2,
            PurchaseRequest::STATUS_PURCHASED => 3,
            PurchaseRequest::STATUS_REJECTED => -1,
            PurchaseRequest::STATUS_CANCELLED => -1,
        ];

        // Find the target request (furthest status)
        $targetRequest = $purchaseRequests->sortByDesc(function ($pr) use ($statusPriority) {
            return $statusPriority[$pr->status] ?? 0;
        })->first();

        $sourceRequests = $purchaseRequests->filter(fn($pr) => $pr->id !== $targetRequest->id);

        DB::beginTransaction();
        try {
            $movedItemsCount = 0;

            // Move all items from source requests to target request
            foreach ($sourceRequests as $sourceRequest) {
                $itemsToMove = $sourceRequest->items;

                foreach ($itemsToMove as $item) {
                    $item->purchase_request_id = $targetRequest->id;
                    $item->save();
                    $movedItemsCount++;
                }

                // Void any open Stripe invoices on source requests
                if ($sourceRequest->stripe_invoice_id && $sourceRequest->status !== PurchaseRequest::STATUS_PAID) {
                    try {
                        $stripe = $sourceRequest->stripeClient();
                        $invoice = $stripe->invoices->retrieve($sourceRequest->stripe_invoice_id);
                        if ($invoice->status === 'open') {
                            $stripe->invoices->voidInvoice($sourceRequest->stripe_invoice_id);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Could not void invoice during merge', ['id' => $sourceRequest->stripe_invoice_id]);
                    }
                }

                // Delete the source request (items already moved)
                $sourceRequest->delete();
            }

            DB::commit();

            Log::info('Purchase requests merged successfully', [
                'target_request_id' => $targetRequest->id,
                'source_request_ids' => $sourceRequests->pluck('id')->toArray(),
                'items_moved' => $movedItemsCount,
                'user_id' => $targetRequest->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully merged " . ($sourceRequests->count() + 1) . " requests. Moved {$movedItemsCount} items.",
                'data' => $targetRequest->fresh()->load(['user', 'items'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to merge purchase requests', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to merge purchase requests: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createQuote(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json(['success' => false, 'message' => 'Request is not in pending review state'], 400);
        }

        // Store-source PRs follow a different quote path:
        //  • prices are already in MXN with markup baked in (set at checkout)
        //  • Velonie verified each item's availability beforehand
        //  • only items marked `available` get billed (unavailable stay
        //    visible on the PR but skip the Stripe invoice)
        //  • no manual items_total/shipping/sales_tax input required
        if ($purchaseRequest->isStore()) {
            return $this->createStoreQuote($request, $purchaseRequest);
        }

        // ---- Original assisted-PR quote logic (unchanged) ----
        $validated = $request->validate([
            'items_total' => 'required|numeric|min:0',
            'shipping_cost' => 'required|numeric|min:0',
            'sales_tax' => 'required|numeric|min:0',
            'admin_notes' => 'nullable|string',
            'payment_method' => 'nullable|in:stripe,manual_deposit',
        ]);

        DB::beginTransaction();

        try {
            // 1. Calculate Totals in USD
            $subtotalUsd = floatval($validated['items_total']) + floatval($validated['shipping_cost']) + floatval($validated['sales_tax']);

            // 2. Apply 8% Markup
            $markupPercentage = 0.08;
            $feeUsd = round($subtotalUsd * $markupPercentage, 2);
            $totalUsd = $subtotalUsd + $feeUsd;

            // 3. Determine Payment Method
            $paymentMethod = $validated['payment_method'] ?? PurchaseRequest::PAYMENT_METHOD_STRIPE;

            // 4a. STRIPE PAYMENT FLOW
            if ($paymentMethod === PurchaseRequest::PAYMENT_METHOD_STRIPE) {
                // Convert to MXN
                $exchangeRate = 18.00;
                $subtotalMxn = round($subtotalUsd * $exchangeRate, 2);
                $feeMxn = round($feeUsd * $exchangeRate, 2);

                // Shopping Stripe account — separate customer record from
                // the main account. Lazily created on first invoice.
                $user = $purchaseRequest->user;
                $shoppingCustomerId = $user->stripeShoppingCustomerId();

                $stripe = StripeAccount::shopping();

                $stripeInvoice = $stripe->invoices->create([
                    'customer' => $shoppingCustomerId,
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
                    'customer' => $shoppingCustomerId,
                    'invoice' => $stripeInvoice->id,
                    'amount' => intval($subtotalMxn * 100),
                    'currency' => 'mxn',
                    'description' => "Cost of Goods (Products, Shipping & Tax) - \${$subtotalUsd} USD @ {$exchangeRate} MXN/USD",
                ]);

                $stripe->invoiceItems->create([
                    'customer' => $shoppingCustomerId,
                    'invoice' => $stripeInvoice->id,
                    'amount' => intval($feeMxn * 100),
                    'currency' => 'mxn',
                    'description' => "Service Fee (8%) - \${$feeUsd} USD @ {$exchangeRate} MXN/USD",
                ]);

                $stripe->invoices->finalizeInvoice($stripeInvoice->id);
                $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

                // Update Model with Stripe details
                $purchaseRequest->update([
                    'items_total' => $validated['items_total'],
                    'shipping_cost' => $validated['shipping_cost'],
                    'sales_tax' => $validated['sales_tax'],
                    'processing_fee' => $feeUsd,
                    'total_amount' => $totalUsd,
                    'currency' => 'usd',
                    'payment_method' => PurchaseRequest::PAYMENT_METHOD_STRIPE,
                    'status' => PurchaseRequest::STATUS_QUOTED,
                    'stripe_invoice_id' => $stripeInvoice->id,
                    'stripe_account' => PurchaseRequest::STRIPE_ACCOUNT_SHOPPING,
                    'payment_link' => $sentInvoice->hosted_invoice_url,
                    'quote_sent_at' => now(),
                    'admin_notes' => $validated['admin_notes'],
                ]);
            }
            // 4b. MANUAL DEPOSIT PAYMENT FLOW
            else {
                // Skip Stripe entirely, just calculate and store totals
                $user = $purchaseRequest->user;

                $purchaseRequest->update([
                    'items_total' => $validated['items_total'],
                    'shipping_cost' => $validated['shipping_cost'],
                    'sales_tax' => $validated['sales_tax'],
                    'processing_fee' => $feeUsd,
                    'total_amount' => $totalUsd,
                    'currency' => 'usd',
                    'payment_method' => PurchaseRequest::PAYMENT_METHOD_MANUAL_DEPOSIT,
                    'status' => PurchaseRequest::STATUS_QUOTED,
                    'stripe_invoice_id' => null,
                    'payment_link' => null,
                    'quote_sent_at' => now(),
                    'admin_notes' => $validated['admin_notes'],
                ]);
            }

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

    /**
     * Quote a STORE-source PR: items are already priced in MXN with markup
     * baked in, Velonie has just confirmed which ones are actually in stock,
     * so we just need to (1) validate every item was checked, (2) sum the
     * available items, (3) generate a Stripe invoice with one line per
     * available item, (4) flip status to `quoted`.
     */
    private function createStoreQuote(Request $request, PurchaseRequest $purchaseRequest)
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string',
        ]);

        // Reload items so we have the freshest stock_status values
        $purchaseRequest->load('items');

        if (! $purchaseRequest->allItemsStockChecked()) {
            return response()->json([
                'success' => false,
                'message' => 'Verifica el stock de cada producto antes de cotizar',
            ], 422);
        }

        $availableItems = $purchaseRequest->availableItems();
        if ($availableItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Ningún producto disponible — no se puede generar cotización',
            ], 422);
        }

        $totalMxn = $availableItems->sum(fn($item) => floatval($item->price) * (int) $item->quantity);
        $totalMxn = round($totalMxn, 2);

        DB::beginTransaction();

        try {
            $user = $purchaseRequest->user;
            $shoppingCustomerId = $user->stripeShoppingCustomerId();

            $stripe = StripeAccount::shopping();

            $stripeInvoice = $stripe->invoices->create([
                'customer'          => $shoppingCustomerId,
                'currency'          => 'mxn',
                'collection_method' => 'send_invoice',
                'days_until_due'    => 3,
                'description'       => "Boxly Store - {$purchaseRequest->request_number}",
                'metadata'          => [
                    'type'                => 'purchase_request_invoice',
                    'purchase_request_id' => $purchaseRequest->id,
                    'request_number'      => $purchaseRequest->request_number,
                    'source'              => PurchaseRequest::SOURCE_STORE,
                ],
                'auto_advance' => false,
            ]);

            // One Stripe invoice line per available item — gives the
            // customer a clean breakdown of what they're paying for.
            foreach ($availableItems as $item) {
                $opts = $item->options ?? [];
                $suffix = trim(implode(' / ', array_filter([
                    $opts['size']   ?? null,
                    $opts['color']  ?? null,
                    $opts['length'] ?? null,
                ])));
                $name = $suffix ? "{$item->product_name} ({$suffix})" : $item->product_name;

                $stripe->invoiceItems->create([
                    'customer'    => $shoppingCustomerId,
                    'invoice'     => $stripeInvoice->id,
                    'amount'      => intval(round($item->price * 100)) * (int) $item->quantity,
                    'currency'    => 'mxn',
                    'description' => $item->quantity > 1 ? "{$name} × {$item->quantity}" : $name,
                ]);
            }

            $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            $purchaseRequest->update([
                'items_total'       => $totalMxn,
                'shipping_cost'     => 0,
                'sales_tax'         => 0,
                'processing_fee'    => 0, // markup already in product price
                'total_amount'      => $totalMxn,
                'currency'          => 'mxn',
                'payment_method'    => PurchaseRequest::PAYMENT_METHOD_STRIPE,
                'status'            => PurchaseRequest::STATUS_QUOTED,
                'stripe_invoice_id' => $stripeInvoice->id,
                'stripe_account'    => PurchaseRequest::STRIPE_ACCOUNT_SHOPPING,
                'payment_link'      => $sentInvoice->hosted_invoice_url,
                'quote_sent_at'     => now(),
                'admin_notes'       => $validated['admin_notes'] ?? $purchaseRequest->admin_notes,
            ]);

            DB::commit();

            try {
                Mail::to($user)->queue(new PurchaseRequestQuoteSent($purchaseRequest));
            } catch (\Exception $e) {
                Log::error('Failed to queue store quote email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Cotización enviada al cliente',
                'data'    => $purchaseRequest->fresh()->load(['items', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to quote store PR', [
                'pr_id' => $purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Transition a paid PurchaseRequest to purchased + create the follow-up Order.
     * Caller MUST verify the PR is in `paid` state. Throws on failure so callers
     * (single + bulk) can wrap in their own transaction/error handling.
     */
    private function processPurchase(PurchaseRequest $purchaseRequest): Order
    {
        $user = $purchaseRequest->user;

        $order = Order::create([
            'user_id'         => $user->id,
            'order_number'    => Order::generateOrderNumber(),
            'tracking_number' => Order::generateTrackingNumber(),
            'status'          => Order::STATUS_AWAITING_PACKAGES,
            'delivery_address'=> $user->address,
            'is_rural'        => false,
            'currency'        => 'mxn',
            'completed_at'    => now(),
        ]);

        // For store-source PRs, only the items Velonie marked `available`
        // get added to the Order (they're the only ones the customer paid
        // for). Unavailable items stay on the PR for visibility but don't
        // become Order items. Assisted PRs ignore stock_status (it's null).
        $itemsToCopy = $purchaseRequest->isStore()
            ? $purchaseRequest->availableItems()
            : $purchaseRequest->items;

        foreach ($itemsToCopy as $prItem) {
            $imageUrl = null;
            if ($prItem->image_url) {
                $imageUrl = $prItem->image_full_url;
            } elseif ($prItem->product_image_url) {
                $imageUrl = $prItem->product_image_url;
            }

            (new OrderItem([
                'order_id'                => $order->id,
                'product_name'            => $prItem->product_name,
                'product_url'             => $prItem->product_url,
                'product_image_url'       => $imageUrl,
                'quantity'                => $prItem->quantity,
                'declared_value'          => $prItem->price,
                'purchase_request_item_id'=> $prItem->id,
                'is_assisted_purchase'    => true,
            ]))->save();
        }

        $purchaseRequest->update([
            'status'       => PurchaseRequest::STATUS_PURCHASED,
            'purchased_at' => now(),
        ]);

        $order->update([
            'declared_value' => $order->calculateTotalDeclaredValue(),
            'iva_amount'     => $order->calculateIVA(),
        ]);

        return $order;
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
            $order = $this->processPurchase($purchaseRequest);

            DB::commit();

            $user = $purchaseRequest->user;

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
                $purchaseRequest->stripeClient()->invoices->voidInvoice($purchaseRequest->stripe_invoice_id);
            } catch (\Exception $e) {
                Log::warning('Could not void invoice on rejection', ['id' => $purchaseRequest->stripe_invoice_id]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Request rejected']);
    }
}