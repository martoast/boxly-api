<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchasedProduct;
use App\Models\User;
use App\Mail\PurchaseRequestQuoteSent;
use App\Mail\PurchaseRequestItemsPurchased;
// Removed PurchaseRequestCreated import to prevent notification on admin create
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        return response()->json([
            'success' => true,
            // Tie-break on the primary key so paging is stable (see expenses).
            'data' => $query->latest()->orderBy('id', 'desc')->paginate(20)
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

    /**
     * Per-item cost breakdown — Velonie's new step on store-source PRs.
     *
     * After verifying availability at the source store, she enters the
     * actual taxes + shipping the store charged her at checkout, plus a
     * Boxly commission % (default 15% — defined in services.commission).
     * The system computes the per-item final USD on save and persists
     * it so the quote step can sum it up cleanly.
     *
     * Marking an item available via this endpoint also flips the
     * stock_status, so it replaces updateItemStockStatus for the
     * "happy path" — the older endpoint stays around for unavailable.
     */
    public function updateItemCostBreakdown(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestItem $item)
    {
        if ($item->purchase_request_id !== $purchaseRequest->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this PR'], 422);
        }
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json(['success' => false, 'message' => 'Cost breakdown can only be edited while PR is in pending_review'], 400);
        }

        $validated = $request->validate([
            'tax_usd'             => 'required|numeric|min:0',
            'shipping_usd'        => 'required|numeric|min:0',
            'commission_percent'  => 'required|numeric|min:0|max:100',
        ]);

        $item->fill([
            'tax_usd'            => $validated['tax_usd'],
            'shipping_usd'       => $validated['shipping_usd'],
            'commission_percent' => $validated['commission_percent'],
            'stock_status'       => PurchaseRequestItem::STOCK_AVAILABLE,
            'stock_checked_at'   => now(),
            'stock_checked_by'   => $request->user()->id,
        ]);
        $item->final_usd = $item->computeFinalUsd();
        $item->save();

        return response()->json([
            'success' => true,
            'data'    => $item->fresh(),
        ]);
    }

    /**
     * Unified per-item update for the redesigned PR detail page.
     *
     * Replaces updateItemStockStatus + updateItemCostBreakdown with a single
     * endpoint that accepts any combination of: price (USD unit price the
     * customer will be billed for), quantity, stock_status, notes. Admin's
     * inline edits on the detail page all flow through here. Pending-review
     * only — once quoted/paid we lock the items.
     */
    public function updateItem(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestItem $item)
    {
        if ($item->purchase_request_id !== $purchaseRequest->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this PR'], 422);
        }
        if (! in_array($purchaseRequest->status, self::ITEM_EDITABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Items can only be edited before the PR is marked purchased.',
            ], 400);
        }

        $validated = $request->validate([
            'price'        => 'sometimes|numeric|min:0',
            'quantity'     => 'sometimes|integer|min:1',
            'stock_status' => 'sometimes|in:unverified,available,unavailable',
            'notes'        => 'sometimes|nullable|string|max:500',
        ]);

        // Stock-check audit metadata when status changes
        if (array_key_exists('stock_status', $validated)) {
            $validated['stock_checked_at'] = $validated['stock_status'] === PurchaseRequestItem::STOCK_UNVERIFIED
                ? null
                : now();
            $validated['stock_checked_by'] = $validated['stock_status'] === PurchaseRequestItem::STOCK_UNVERIFIED
                ? null
                : $request->user()->id;
        }

        $item->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $item->fresh(),
        ]);
    }

    /**
     * Statuses where admins can still mutate the line-up. Once a PR is
     * `purchased` the items have been materialised into an Order, so
     * touching them here would silently drift the two records apart;
     * rejected/cancelled PRs are terminal and shouldn't be edited.
     */
    private const ITEM_EDITABLE_STATUSES = [
        PurchaseRequest::STATUS_PENDING_REVIEW,
        PurchaseRequest::STATUS_QUOTED,
        PurchaseRequest::STATUS_PAID,
    ];

    /**
     * Remove an item from a pre-purchased PR.
     *
     * Open through `paid` so the shopping manager can still drop a
     * substituted/cancelled line that the customer changed their mind
     * on after the invoice was paid, then re-add the new item and
     * click "Marcar como Comprado" to generate the Order.
     *
     * The model's boot hook handles cleanup of the Spaces image
     * (see PurchaseRequestItem::deleted listener). Empty PRs are
     * allowed — admin can reject the empty PR afterwards if they
     * choose, but we don't force that here.
     */
    public function deleteItem(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestItem $item)
    {
        if ($item->purchase_request_id !== $purchaseRequest->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this PR'], 422);
        }
        if (! in_array($purchaseRequest->status, self::ITEM_EDITABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Items can only be removed before the PR is marked purchased.',
            ], 400);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'data'    => $purchaseRequest->fresh(['items']),
        ]);
    }

    /**
     * Add a new item to an existing pre-purchased PR.
     *
     * Used when the customer substituted what they actually wanted
     * after the PR was already quoted/paid. Defaults the new item to
     * stock_status=available so it doesn't get filtered out of the
     * downstream Order on processPurchase.
     */
    public function addItem(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! in_array($purchaseRequest->status, self::ITEM_EDITABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Items can only be added before the PR is marked purchased.',
            ], 400);
        }

        $validated = $request->validate([
            'product_name'      => 'required|string|max:255',
            'product_url'       => 'required|string|max:2000',
            'product_image_url' => 'nullable|string|max:2000',
            'price'             => 'required|numeric|min:0',
            'quantity'          => 'required|integer|min:1',
            'options'           => 'nullable|array',
            'notes'             => 'nullable|string|max:500',
            'stock_status'      => 'sometimes|in:unverified,available,unavailable',
        ]);

        $stockStatus = $validated['stock_status'] ?? PurchaseRequestItem::STOCK_AVAILABLE;

        $item = PurchaseRequestItem::create([
            'purchase_request_id' => $purchaseRequest->id,
            'product_name'        => $validated['product_name'],
            'product_url'         => $validated['product_url'],
            'product_image_url'   => $validated['product_image_url'] ?? null,
            'price'               => $validated['price'],
            'quantity'            => $validated['quantity'],
            'options'             => $validated['options'] ?? null,
            'notes'               => $validated['notes'] ?? null,
            'stock_status'        => $stockStatus,
            'stock_checked_at'    => $stockStatus === PurchaseRequestItem::STOCK_UNVERIFIED ? null : now(),
            'stock_checked_by'    => $stockStatus === PurchaseRequestItem::STOCK_UNVERIFIED ? null : $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $item->fresh(),
        ], 201);
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        $purchaseRequest->load(['user', 'items', 'stores']);
        $payload = $purchaseRequest->toArray();

        // Resolve the store_categories JSON map into a per-store breakdown
        // with category names so the admin panel can render "Nike → Sneakers,
        // Sportswear" without doing a second fetch for category labels.
        if ($purchaseRequest->isInPerson()) {
            $payload['in_person_breakdown'] = $purchaseRequest->inPersonStoreBreakdown()
                ->map(fn ($row) => [
                    'store_id'       => $row['store']->id,
                    'store_name'     => $row['store']->name,
                    'category_names' => $row['category_names'],
                ])
                ->values()
                ->toArray();
        }

        return response()->json(['success' => true, 'data' => $payload]);
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
            'store_costs' => 'nullable|array',
            'store_costs.*.shipping' => 'nullable|numeric|min:0',
            'store_costs.*.tax' => 'nullable|numeric|min:0',
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

    /**
     * Unified quote flow — applies to ALL PRs regardless of source.
     *
     * ONE number in: `amount_spent` — exactly what Boxly paid at the US
     * stores, everything included (product prices, US store shipping,
     * sales tax, whatever else the receipts show). The admin reads it off
     * the receipts; we never re-derive it from item prices, because
     * splitting a multi-store receipt into products / shipping / tax by
     * hand is what was producing wrong invoices.
     *
     * Total = amount_spent + amount_spent × commission% (default 15).
     *
     * Stripe gets two lines — the purchase and the commission — so the
     * customer is billed exactly the two numbers the admin saw. The item
     * list still travels with the quote email, so nothing is hidden.
     */
    public function createQuote(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json(['success' => false, 'message' => 'Request is not in pending review state'], 400);
        }

        $validated = $request->validate([
            'amount_spent'            => 'required|numeric|min:0.01',
            'processing_fee_percent'  => 'nullable|numeric|min:0|max:100',
            'admin_notes'             => 'nullable|string',
        ]);

        $amountSpentUsd = round((float) $validated['amount_spent'], 2);
        $feePercent     = (float) ($validated['processing_fee_percent']
            ?? config('services.commission.default_percent', 15));

        $purchaseRequest->load('items');

        // Billable items: exclude `unavailable` (stock check failed) and
        // `wishlist` (in-person pre-trip placeholder — only billable after
        // admin flips it to `available` with the real price found at the
        // mall). 'unverified' / null items still bill — that path covers
        // legacy assisted PRs where stock verification isn't part of the
        // flow.
        $billableItems = $purchaseRequest->items->filter(
            fn ($i) => ! in_array($i->stock_status, [
                PurchaseRequestItem::STOCK_UNAVAILABLE,
                PurchaseRequestItem::STOCK_WISHLIST,
            ], true),
        );
        if ($billableItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay productos para cotizar — agrega o marca disponibles antes.',
            ], 422);
        }

        // In-person PRs paid the $10/store scheduling deposit upfront via
        // Stripe Checkout — we don't double-charge it on the post-trip
        // quote. What was spent + the commission on it, nothing else.
        $feeUsd   = round($amountSpentUsd * ($feePercent / 100), 2);
        $totalUsd = round($amountSpentUsd + $feeUsd, 2);

        DB::beginTransaction();

        try {
            $user = $purchaseRequest->user;
            $shoppingCustomerId = $user->stripeShoppingCustomerId();
            $stripe = StripeAccount::shopping();

            // Invoice in USD — Stripe (or the customer's issuing bank)
            // handles the conversion to MXN at payment time. We don't
            // touch FX ourselves; the rate the customer pays at is
            // whatever their bank quotes the moment they tap the link.
            $invoiceDescription = "Boxly — Solicitud de Compra {$purchaseRequest->request_number}";
            if ($purchaseRequest->isInPerson()) {
                $invoiceDescription .= sprintf(
                    ' — Compra en persona Las Américas (reserva de $%.2f USD ya pagada)',
                    (float) ($purchaseRequest->deposit_amount_usd ?? 0),
                );
            }

            $stripeInvoice = $stripe->invoices->create([
                'customer'          => $shoppingCustomerId,
                'currency'          => 'usd',
                'collection_method' => 'send_invoice',
                'days_until_due'    => 3,
                'description'       => $invoiceDescription,
                'metadata' => [
                    'type'                => 'purchase_request_invoice',
                    'purchase_request_id' => $purchaseRequest->id,
                    'request_number'      => $purchaseRequest->request_number,
                    'source'              => (string) $purchaseRequest->source,
                ],
                'auto_advance' => false,
            ]);

            // Two lines, mirroring what the admin typed: the purchase and the
            // commission on it. Their cents sum to $totalUsd exactly (both are
            // rounded to 2dp before this point), so Stripe cannot bill a number
            // the PR doesn't record.
            $addLine = function (string $description, float $amountUsd) use (
                $stripe, $shoppingCustomerId, $stripeInvoice
            ) {
                $cents = (int) round($amountUsd * 100);
                if ($cents === 0) {
                    return; // don't clutter the invoice with $0.00 rows
                }
                $stripe->invoiceItems->create([
                    'customer'    => $shoppingCustomerId,
                    'invoice'     => $stripeInvoice->id,
                    'amount'      => $cents,
                    'currency'    => 'usd',
                    'description' => mb_substr($description, 0, 250),
                ]);
            };

            $itemCount = $billableItems->count();
            $addLine(
                sprintf(
                    'Compra de %d %s en tiendas de EE. UU. (incluye envío e impuestos) / Purchase incl. US shipping & tax',
                    $itemCount,
                    $itemCount === 1 ? 'producto' : 'productos',
                ),
                $amountSpentUsd,
            );

            // 15 → "15", 12.5 → "12.5" — never "13%" on a 12.5% commission.
            $feeLabel = rtrim(rtrim(number_format($feePercent, 1, '.', ''), '0'), '.');
            $addLine("Comisión Boxly / Boxly commission ({$feeLabel}%)", $feeUsd);

            $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            $purchaseRequest->update([
                // items_total is our cost basis (what we actually paid, all in);
                // shipping/tax are no longer captured separately, so they stay 0
                // rather than holding a stale split the invoice never used.
                'items_total'       => $amountSpentUsd,
                'shipping_cost'     => 0,
                'sales_tax'         => 0,
                'store_costs'       => null,
                'processing_fee'    => $feeUsd,
                'total_amount'      => $totalUsd,
                'total_usd'         => $totalUsd,
                'fx_rate_used'      => null,
                'currency'          => 'usd',
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
                Log::info('Quote email queued for ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue quote email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Cotización enviada al cliente',
                'data'    => $purchaseRequest->fresh()->load(['items', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to quote purchase request', [
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

        // Auto-seed a Compras (purchased products) tracker row for Velonie,
        // pre-filled from the PR. She fills the store's order # and flips it to
        // delivered on arrival. firstOrCreate keyed on the PR avoids duplicates
        // if the purchase flow is ever re-run.
        $itemsSummary = $itemsToCopy
            ->map(fn ($i) => "{$i->quantity}x {$i->product_name}")
            ->implode("\n");

        PurchasedProduct::firstOrCreate(
            ['purchase_request_id' => $purchaseRequest->id],
            [
                'user_id'       => auth()->id(),
                'customer_name' => $user->name,
                'contact_phone' => $user->phone,
                'items'         => $itemsSummary,
                'status'        => PurchasedProduct::STATUS_PENDING,
                'order_date'    => now(),
            ]
        );

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

    /**
     * Live USD→MXN rate. Cached 10min so a quote burst doesn't hammer
     * Frankfurter and so all line items in one PR see the same rate.
     * Falls back to services.exchange_rate.usd_to_mxn if upstream is
     * unreachable.
     */
    protected function fetchLiveFxRate(): float
    {
        return Cache::remember('fx:usd-mxn', now()->addMinutes(10), function () {
            try {
                $res = Http::timeout(5)->get('https://api.frankfurter.dev/v1/latest', [
                    'base' => 'USD', 'symbols' => 'MXN',
                ]);
                $rate = (float) ($res->json('rates.MXN') ?? 0);
                if ($rate > 0) return round($rate, 4);
            } catch (\Throwable $e) {
                Log::warning('FX rate fetch failed during quote', ['error' => $e->getMessage()]);
            }
            return (float) config('services.exchange_rate.usd_to_mxn', 17.5);
        });
    }
}