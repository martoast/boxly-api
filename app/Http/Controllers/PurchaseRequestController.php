<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\ShoppingTrip;
use App\Models\Store;
use App\Mail\PurchaseRequestCreated;
use App\Mail\PurchaseRequestCreatedTeamNotification;
use App\Mail\PurchaseRequestInPersonScheduled;
use App\Models\User;
use App\Services\StripeAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $requests = PurchaseRequest::with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Create a new purchase request
     */
    public function store(Request $request)
    {
        $request->validate([
            'currency' => 'nullable|in:usd,mxn',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_url' => 'required|string|max:2000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable', // Can be array or JSON string via FormData
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240', // 10MB max
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // 1. Create the Request Ticket
            $pr = PurchaseRequest::create([
                'user_id' => $user->id,
                'request_number' => PurchaseRequest::generateRequestNumber(),
                'status' => PurchaseRequest::STATUS_PENDING_REVIEW,
                'currency' => $request->input('currency', 'usd'),
            ]);

            // 2. Process Items
            $itemsInput = $request->input('items');

            foreach ($itemsInput as $index => $itemData) {
                
                // Handle options: If sent via FormData, it might be a JSON string or an array
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
                // Check if a file exists for this specific item index
                if ($request->hasFile("items.{$index}.image")) {
                    $file = $request->file("items.{$index}.image");
                    
                    // Create storage path
                    $userName = Str::slug($user->name);
                    $storagePath = "users/{$userName}-{$user->id}/requests/{$pr->request_number}/items/{$item->id}";
                    
                    $filename = "image-" . time() . "." . $file->getClientOriginalExtension();
                    
                    // Upload
                    $path = Storage::disk('spaces')->putFileAs(
                        $storagePath,
                        $file,
                        $filename,
                        'public'
                    );
                    
                    $url = config('filesystems.disks.spaces.url') . '/' . $path;
                    
                    // Update item with file info
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

            Log::info('Purchase Request created', ['id' => $pr->id, 'user_id' => $user->id]);

            // Customer confirmation
            try {
                Mail::to($user)->queue(new PurchaseRequestCreated($pr));
                Log::info('Purchase Request confirmation email queued for ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue purchase request email: ' . $e->getMessage());
            }

            // Internal alert to the shopping team — Velonie can review and quote
            // right away. Admins excluded (they have the dashboard).
            try {
                $pr->load(['items', 'user']);
                $teamEmails = User::query()
                    ->where('role', User::ROLE_EMPLOYEE)
                    ->where('team', User::TEAM_SHOPPING)
                    ->pluck('email')
                    ->all();
                if (! empty($teamEmails)) {
                    Mail::to($teamEmails)->queue(new PurchaseRequestCreatedTeamNotification($pr));
                }
            } catch (\Exception $e) {
                Log::error('Failed to queue PR-created team notification: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Request submitted successfully.',
                'data' => $pr->load('items')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Purchase Request Create Failed: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create request',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Re-create a Stripe Checkout Session for an in-person PR that's still
     * sitting in awaiting_deposit. Customers come back to the PR detail
     * after a cancel/timeout and need a fresh paywall URL — old sessions
     * expire (~24h) or get marked complete server-side once paid.
     *
     * Always mints a new session; if a previous one was paid we'd already
     * have flipped the PR to pending_review and this endpoint would refuse.
     */
    public function createDepositCheckout(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        if (! $purchaseRequest->isInPerson()) {
            return response()->json(['success' => false, 'message' => 'Not an in-person request'], 400);
        }
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_AWAITING_DEPOSIT) {
            return response()->json(['success' => false, 'message' => 'Deposit is no longer pending'], 400);
        }

        try {
            $user  = $purchaseRequest->user;
            $trip  = $purchaseRequest->shoppingTrip;
            $stripe = StripeAccount::shopping();

            $tripDateFormatted = $trip && $trip->trip_date instanceof \Carbon\Carbon
                ? $trip->trip_date->isoFormat('D [de] MMMM')
                : (string) ($trip?->trip_date ?? '');

            $session = $stripe->checkout->sessions->create([
                'mode'                  => 'payment',
                'customer'              => $user->stripeShoppingCustomerId(),
                'line_items'            => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => (int) round((float) $purchaseRequest->deposit_amount_usd * 100),
                        'product_data' => [
                            'name'        => 'Boxly — Reserva de visita en persona',
                            'description' => sprintf(
                                '%d tienda(s) en Las Américas el %s · Solicitud %s',
                                (int) $purchaseRequest->in_person_store_count,
                                $tripDateFormatted,
                                $purchaseRequest->request_number,
                            ),
                        ],
                    ],
                ]],
                'adaptive_pricing'      => ['enabled' => true],
                'metadata'              => [
                    'type'                => 'in_person_deposit',
                    'purchase_request_id' => $purchaseRequest->id,
                    'request_number'      => $purchaseRequest->request_number,
                ],
                'payment_intent_data'   => [
                    'metadata' => [
                        'type'                => 'in_person_deposit',
                        'purchase_request_id' => $purchaseRequest->id,
                        'request_number'      => $purchaseRequest->request_number,
                    ],
                ],
                'success_url'           => config('app.frontend_url') . '/in-person/success?ref=' . urlencode($purchaseRequest->request_number),
                // Cancel takes them back to this PR's detail so the awaiting-
                // deposit banner is right there with the same Pay CTA.
                'cancel_url'            => config('app.frontend_url') . '/app/purchase-requests/' . $purchaseRequest->id,
            ]);

            $purchaseRequest->update([
                'deposit_checkout_session_id' => $session->id,
            ]);

            return response()->json([
                'success'      => true,
                'checkout_url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to re-create in-person deposit Checkout Session', [
                'pr_id' => $purchaseRequest->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar el pago. Intenta de nuevo en unos minutos.',
            ], 500);
        }
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $purchaseRequest->load(['items', 'shoppingTrip', 'stores']);
        $payload = $purchaseRequest->toArray();

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
     * Create an in-person shopping PR — customer schedules a Boxly team
     * member to physically shop at Las Americas Outlets on their behalf.
     *
     * Differences from the online flow:
     *  - No payment at submission; quote happens after the trip.
     *  - Items go in with stock_status=wishlist (excluded from billing
     *    until admin flips them to 'available' with the real price).
     *  - PR carries shopping_trip_id, store/category pivots, customer
     *    notes, minimum budget, and a store-count snapshot used by
     *    createQuote to compute the $10/store service fee.
     */
    public function storeInPerson(Request $request)
    {
        $validated = $request->validate([
            'shopping_trip_id'    => 'required|integer|exists:shopping_trips,id',
            'store_ids'           => 'required|array|min:1',
            'store_ids.*'         => 'required|integer|exists:stores,id',
            // Per-store category map: { "<store_id>": [<category_id>, ...] }.
            // Optional — empty/missing keys mean "no preference at that store".
            'store_categories'              => 'nullable|array',
            'store_categories.*'            => 'array',
            'store_categories.*.*'          => 'integer|exists:categories,id',
            'minimum_budget_usd'  => 'required|numeric|min:0',
            'customer_notes'      => 'nullable|string|max:2000',
            // Wishlist items are optional — a customer may schedule a trip
            // without a specific list and trust Boxly to find good deals.
            'wishlist'                       => 'nullable|array',
            'wishlist.*.product_name'        => 'required_with:wishlist|string|max:255',
            'wishlist.*.product_url'         => 'nullable|string|max:2000',
            'wishlist.*.product_image_url'   => 'nullable|string|max:2000',
            'wishlist.*.notes'               => 'nullable|string|max:500',
            'wishlist.*.options'             => 'nullable',
            'wishlist.*.quantity'            => 'nullable|integer|min:1',
            'wishlist.*.image'               => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        // Trip must be in the open state — closed/completed trips shouldn't
        // accept new bookings. Reload from DB rather than trusting the FK
        // existence check so we see the latest status.
        $trip = ShoppingTrip::findOrFail($validated['shopping_trip_id']);
        if ($trip->status !== ShoppingTrip::STATUS_OPEN || $trip->trip_date->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This trip is no longer accepting bookings.',
            ], 422);
        }

        // Every selected store must be flagged in-person — defence against
        // somebody crafting a request with an online-only store id.
        $storeCount = Store::whereIn('id', $validated['store_ids'])
            ->inPersonAvailable()
            ->count();
        if ($storeCount !== count($validated['store_ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected stores are not available for in-person shopping.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = $request->user();

            // Filter the per-store map down to only the stores the customer
            // actually selected — defensive against payload drift if the UI
            // ever sends stale entries.
            $storeCategories = collect($validated['store_categories'] ?? [])
                ->only($validated['store_ids'])
                ->filter(fn ($cats) => is_array($cats) && count($cats) > 0)
                ->map(fn ($cats) => array_values(array_unique(array_map('intval', $cats))))
                ->toArray();

            // $10/store deposit locked at submission time — even if config
            // changes later, this PR is billed at the rate the customer saw
            // when they booked.
            $perStoreFee = (float) config('services.in_person.per_store_fee_usd', 10);
            $storeCount = count($validated['store_ids']);
            $depositAmountUsd = round($perStoreFee * $storeCount, 2);

            $pr = PurchaseRequest::create([
                'user_id'              => $user->id,
                'request_number'       => PurchaseRequest::generateRequestNumber(),
                // Sits in awaiting_deposit until the Stripe webhook flips it
                // — admin queues filter to pending_review so unpaid bookings
                // don't clutter Velonie's workflow.
                'status'               => PurchaseRequest::STATUS_AWAITING_DEPOSIT,
                'source'               => PurchaseRequest::SOURCE_IN_PERSON,
                'shopping_trip_id'     => $trip->id,
                'currency'             => 'usd',
                'customer_notes'       => $validated['customer_notes'] ?? null,
                'minimum_budget_usd'   => $validated['minimum_budget_usd'],
                'in_person_store_count'=> $storeCount,
                'store_categories'     => empty($storeCategories) ? null : $storeCategories,
                'deposit_amount_usd'   => $depositAmountUsd,
            ]);

            $pr->stores()->sync($validated['store_ids']);

            // Wishlist items become PurchaseRequestItem rows in the wishlist
            // state — same items table as online PRs so admin can use the
            // existing item-edit endpoints to flip wishes into actual buys
            // post-trip.
            foreach (($validated['wishlist'] ?? []) as $index => $itemData) {
                $options = null;
                if (isset($itemData['options'])) {
                    $options = is_string($itemData['options'])
                        ? json_decode($itemData['options'], true)
                        : $itemData['options'];
                }

                $item = PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'product_name'        => $itemData['product_name'],
                    'product_url'         => $itemData['product_url'] ?? null,
                    'product_image_url'   => $itemData['product_image_url'] ?? null,
                    // Price is unknown until the trip — admin fills it in
                    // when flipping the wish to 'available'. Persist 0 so
                    // the not-null decimal column is satisfied.
                    'price'               => 0,
                    'quantity'            => $itemData['quantity'] ?? 1,
                    'options'             => $options,
                    'notes'               => $itemData['notes'] ?? null,
                    'stock_status'        => PurchaseRequestItem::STOCK_WISHLIST,
                ]);

                if ($request->hasFile("wishlist.{$index}.image")) {
                    $file = $request->file("wishlist.{$index}.image");
                    $userName = Str::slug($user->name);
                    $storagePath = "users/{$userName}-{$user->id}/requests/{$pr->request_number}/items/{$item->id}";
                    $filename = "image-" . time() . "." . $file->getClientOriginalExtension();

                    $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                    $url = config('filesystems.disks.spaces.url') . '/' . $path;

                    $item->update([
                        'image_path'      => $path,
                        'image_filename'  => $file->getClientOriginalName(),
                        'image_mime_type' => $file->getClientMimeType(),
                        'image_size'      => $file->getSize(),
                        'image_url'       => $url,
                    ]);
                }
            }

            DB::commit();

            Log::info('In-person PR created (awaiting deposit)', [
                'id'              => $pr->id,
                'user_id'         => $user->id,
                'trip_id'         => $trip->id,
                'stores'          => $storeCount,
                'deposit_usd'     => $depositAmountUsd,
            ]);

            // Confirmation + team-alert emails are queued by the Stripe
            // webhook after the deposit clears — not here. We don't want
            // tire-kicker bookings to spam Velonie with notifications.
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('In-person PR create failed: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule request',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        // Stripe Checkout Session — created OUTSIDE the DB transaction so a
        // Stripe API failure doesn't roll back the PR we just persisted.
        // If session creation fails, the PR stays in awaiting_deposit and
        // can be recovered by re-submitting (admin can clean up orphans).
        try {
            $stripe = StripeAccount::shopping();
            $tripDateFormatted = $trip->trip_date instanceof \Carbon\Carbon
                ? $trip->trip_date->isoFormat('D [de] MMMM')
                : (string) $trip->trip_date;

            $session = $stripe->checkout->sessions->create([
                'mode'                  => 'payment',
                'customer'              => $user->stripeShoppingCustomerId(),
                'line_items'            => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => (int) round($depositAmountUsd * 100),
                        'product_data' => [
                            'name'        => "Boxly — Reserva de visita en persona",
                            'description' => sprintf(
                                '%d tienda(s) en Las Américas el %s · Solicitud %s',
                                $storeCount,
                                $tripDateFormatted,
                                $pr->request_number,
                            ),
                        ],
                    ],
                ]],
                // Adaptive pricing lets Stripe show Mexican customers an
                // MXN-equivalent at checkout while we still settle in USD.
                'adaptive_pricing'      => ['enabled' => true],
                'metadata'              => [
                    'type'                => 'in_person_deposit',
                    'purchase_request_id' => $pr->id,
                    'request_number'      => $pr->request_number,
                ],
                'payment_intent_data'   => [
                    'metadata' => [
                        'type'                => 'in_person_deposit',
                        'purchase_request_id' => $pr->id,
                        'request_number'      => $pr->request_number,
                    ],
                ],
                'success_url'           => config('app.frontend_url') . '/in-person/success?ref=' . urlencode($pr->request_number),
                'cancel_url'            => config('app.frontend_url') . '/in-person/review?cancelled=1',
            ]);

            $pr->update([
                'deposit_checkout_session_id' => $session->id,
            ]);

            return response()->json([
                'success'      => true,
                'message'      => 'Redirect to deposit checkout.',
                'checkout_url' => $session->url,
                'data'         => $pr->fresh()->load(['items', 'shoppingTrip', 'stores']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('In-person deposit Checkout Session failed', [
                'pr_id' => $pr->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar el pago. Intenta de nuevo en unos minutos.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update an existing purchase request
     */
    public function update(Request $request, PurchaseRequest $purchaseRequest)
    {
        // Authorization: Must belong to user AND be in 'pending_review' status
        if ($purchaseRequest->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PENDING_REVIEW) {
            return response()->json([
                'success' => false, 
                'message' => 'Cannot edit request after it has been quoted or processed.'
            ], 400);
        }

        // Validation
        $request->validate([
            'currency' => 'nullable|in:usd,mxn',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_url' => 'required|string|max:2000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable',
            'items.*.notes' => 'nullable|string|max:500',
            // Optional ID for existing items
            'items.*.id' => 'nullable|integer',
            // File validation (for new uploads)
            'items.*.image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();
            $itemsInput = $request->input('items');

            // Update currency if provided
            if ($request->has('currency')) {
                $purchaseRequest->update(['currency' => $request->input('currency')]);
            }
            
            // Track existing item IDs to handle deletions
            $updatedItemIds = [];

            foreach ($itemsInput as $index => $itemData) {
                
                $options = null;
                if (isset($itemData['options'])) {
                    $options = is_string($itemData['options']) 
                        ? json_decode($itemData['options'], true) 
                        : $itemData['options'];
                }

                $item = null;

                // Check if updating existing item
                if (!empty($itemData['id'])) {
                    $item = PurchaseRequestItem::where('id', $itemData['id'])
                        ->where('purchase_request_id', $purchaseRequest->id)
                        ->first();
                }

                if ($item) {
                    // Update existing
                    $item->update([
                        'product_name' => $itemData['product_name'],
                        'product_url' => $itemData['product_url'],
                        'price' => $itemData['price'],
                        'quantity' => $itemData['quantity'],
                        'options' => $options,
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                } else {
                    // Create new
                    $item = PurchaseRequestItem::create([
                        'purchase_request_id' => $purchaseRequest->id,
                        'product_name' => $itemData['product_name'],
                        'product_url' => $itemData['product_url'],
                        'price' => $itemData['price'],
                        'quantity' => $itemData['quantity'],
                        'options' => $options,
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }

                $updatedItemIds[] = $item->id;

                // Handle Image Upload (New or Replacement)
                if ($request->hasFile("items.{$index}.image")) {
                    // Delete old image if exists
                    $item->deleteImage();

                    $file = $request->file("items.{$index}.image");
                    $userName = Str::slug($user->name);
                    $storagePath = "users/{$userName}-{$user->id}/requests/{$purchaseRequest->request_number}/items/{$item->id}";
                    $filename = "image-" . time() . "." . $file->getClientOriginalExtension();
                    
                    $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                    $url = config('filesystems.disks.spaces.url') . '/' . $path;
                    
                    $item->update([
                        'image_path' => $path,
                        'image_filename' => $file->getClientOriginalName(),
                        'image_mime_type' => $file->getClientMimeType(),
                        'image_size' => $file->getSize(),
                        'image_url' => $url,
                    ]);
                }
                // Handle Image Deletion Flag (if frontend sends a flag to remove image)
                elseif (!empty($itemData['remove_image']) && $itemData['remove_image'] == 'true') {
                     $item->deleteImage();
                }
            }

            // Delete items that were removed from the list
            PurchaseRequestItem::where('purchase_request_id', $purchaseRequest->id)
                ->whereNotIn('id', $updatedItemIds)
                ->get()
                ->each(function ($item) {
                    $item->delete(); // Triggers model event to delete image file
                });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Request updated successfully.',
                'data' => $purchaseRequest->load('items')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Purchase Request Update Failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update request'], 500);
        }
    }
}