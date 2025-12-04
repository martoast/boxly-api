<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderBox;
use App\Models\User;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

class AdminOrderManagementController extends Controller
{
    /**
     * Create a new order from scratch (admin can create for any user)
     */
    public function createOrder(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'nullable|string|in:' . implode(',', array_keys(Order::getStatuses())),
            'order_type' => 'nullable|string|in:shipping,crossing',

            // Delivery address required only for shipping orders
            'delivery_address' => 'required_if:order_type,shipping|nullable|array',
            'delivery_address.full_address' => 'nullable|string|max:1000',

            // Individual fields only required if full_address is not provided AND order_type is shipping
            'delivery_address.street' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|string|max:255',
            'delivery_address.exterior_number' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|string|max:100',
            'delivery_address.municipio' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|string|max:100',
            'delivery_address.estado' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|string|max:100',
            'delivery_address.postal_code' => 'required_if:order_type,shipping,delivery_address.full_address,null|nullable|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',

            'is_rural' => 'boolean',
            'notes' => 'nullable|string|max:2000',

            // Boxes support for creation
            'boxes' => 'nullable|array',
            'boxes.*.stripe_price_id' => 'required_with:boxes|string|max:255',
            'boxes.*.quantity' => 'nullable|integer|min:1|max:99',
        ]);

        DB::beginTransaction();

        try {
            $user = User::find($request->user_id);

            // Prepare order data
            $orderData = [
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => $request->status ?? Order::STATUS_COLLECTING,
                'order_type' => $request->order_type ?? 'shipping',
                'currency' => 'mxn',
                'notes' => $request->notes,
            ];

            // For crossing-only orders, force no delivery address and no rural surcharge
            if ($request->order_type === 'crossing') {
                $orderData['delivery_address'] = null;
                $orderData['is_rural'] = false;
            } else {
                $orderData['delivery_address'] = $request->delivery_address;
                $orderData['is_rural'] = $request->is_rural ?? false;
            }

            // Create the order
            $order = new Order($orderData);

            // Skip email notifications for admin-created orders
            $order->skipEmailNotifications = true;
            $order->save();

            // Handle boxes if provided - fetch from Stripe
            if ($request->has('boxes') && is_array($request->boxes) && count($request->boxes) > 0) {
                $boxEntries = $this->fetchBoxDetailsFromStripe($request->boxes);
                $this->saveBoxEntries($order, $boxEntries);
            }

            DB::commit();

            Log::info('Admin created order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $user->id,
                'admin_id' => $request->user()->id,
                'boxes_count' => $request->has('boxes') ? count($request->boxes) : 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->fresh()->load(['user', 'items', 'boxes'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin failed to create order', [
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update any order field (admin override - no restrictions)
     */
    public function updateOrder(Request $request, Order $order)
    {
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|string|in:' . implode(',', array_keys(Order::getStatuses())),
            'order_type' => 'nullable|string|in:shipping,crossing',
            'box_size' => 'nullable|string|in:extra-small,small,medium,large,extra-large',
            'box_price' => 'nullable|numeric|min:0|max:99999.99',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99',
            'iva_amount' => 'nullable|numeric|min:0|max:99999.99',
            'is_rural' => 'nullable|boolean',
            'rural_surcharge' => 'nullable|numeric|min:0|max:9999.99',

            // Allow either full_address OR individual fields for updates
            'delivery_address' => 'nullable|array',
            'delivery_address.full_address' => 'nullable|string|max:1000',
            'delivery_address.street' => 'nullable|string|max:255',
            'delivery_address.exterior_number' => 'nullable|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'nullable|string|max:100',
            'delivery_address.municipio' => 'nullable|string|max:100',
            'delivery_address.estado' => 'nullable|string|max:100',
            'delivery_address.postal_code' => 'nullable|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',

            'total_weight' => 'nullable|numeric|min:0|max:999.99',
            'actual_weight' => 'nullable|numeric|min:0|max:999.99',
            'shipping_cost' => 'nullable|numeric|min:0|max:99999.99',
            'handling_fee' => 'nullable|numeric|min:0|max:9999.99',
            'insurance_fee' => 'nullable|numeric|min:0|max:9999.99',
            'quoted_amount' => 'nullable|numeric|min:0|max:999999.99',
            'quote_breakdown' => 'nullable|array',
            'amount_paid' => 'nullable|numeric|min:0|max:999999.99',
            'deposit_amount' => 'nullable|numeric|min:0|max:999999.99',
            'currency' => 'nullable|string|in:mxn,usd',
            'notes' => 'nullable|string|max:2000',
            'paid_at' => 'nullable|date',
            'deposit_paid_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'processing_started_at' => 'nullable|date',
            'quote_sent_at' => 'nullable|date',
            'quote_expires_at' => 'nullable|date',
            'shipped_at' => 'nullable|date',
            'delivered_at' => 'nullable|date',
            'estimated_delivery_date' => 'nullable|date',
            'actual_delivery_date' => 'nullable|date',
            'guia_number' => 'nullable|string|max:50',
            'stripe_invoice_id' => 'nullable|string|max:255',
            'deposit_invoice_id' => 'nullable|string|max:255',
            'payment_link' => 'nullable|url|max:500',
            'deposit_payment_link' => 'nullable|url|max:500',

            // Boxes support - only need stripe_price_id and quantity, we fetch the rest from Stripe
            'boxes' => 'nullable|array',
            'boxes.*.id' => 'nullable|integer',
            'boxes.*.stripe_price_id' => 'required_with:boxes|string|max:255',
            'boxes.*.quantity' => 'nullable|integer|min:1|max:99',
        ]);

        DB::beginTransaction();

        try {
            // Skip email notifications for admin manual updates
            $order->skipEmailNotifications = true;

            // Separate boxes from other update data
            $updateData = $request->except(['boxes']);

            // If order_type is being changed to 'crossing', clear delivery address and force is_rural to false
            if ($request->has('order_type') && $request->order_type === 'crossing') {
                $updateData['delivery_address'] = null;
                $updateData['is_rural'] = false;
            }
            
            // Handle boxes array if provided
            if ($request->has('boxes')) {
                $boxes = $request->input('boxes');
                
                // Delete existing boxes
                $order->boxes()->delete();
                
                if (is_array($boxes) && count($boxes) > 0) {
                    // Fetch full details from Stripe and save
                    $boxEntries = $this->fetchBoxDetailsFromStripe($boxes);
                    $totalBoxPrice = $this->saveBoxEntries($order, $boxEntries);
                    
                    // Update order's box_price with total
                    $updateData['box_price'] = $totalBoxPrice;
                    
                    // If single box, set legacy fields for backwards compatibility
                    if (count($boxEntries) === 1) {
                        $singleBox = $boxEntries[0];
                        $updateData['box_size'] = $singleBox['box_size'];
                        $updateData['stripe_price_id'] = $singleBox['stripe_price_id'];
                        $updateData['stripe_product_id'] = $singleBox['stripe_product_id'];
                    } else {
                        // Multiple boxes - clear legacy single-box fields
                        $updateData['box_size'] = null;
                        $updateData['stripe_price_id'] = null;
                        $updateData['stripe_product_id'] = null;
                    }
                } else {
                    // Empty boxes array - clear all box-related fields
                    $updateData['box_price'] = null;
                    $updateData['box_size'] = null;
                    $updateData['stripe_price_id'] = null;
                    $updateData['stripe_product_id'] = null;
                }
            }
            
            // Update the order with processed data
            $order->update($updateData);

            DB::commit();

            Log::info('Admin updated order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'admin_id' => $request->user()->id,
                'fields_updated' => array_keys($request->all()),
                'boxes_updated' => $request->has('boxes'),
                'boxes_count' => $request->has('boxes') ? count($request->input('boxes', [])) : null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order->fresh()->load(['user', 'items', 'boxes'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin failed to update order', [
                'order_id' => $order->id,
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Fetch box details from Stripe for each box entry
     * 
     * @param array $boxes Array of boxes with stripe_price_id and quantity
     * @return array Array of box entries with full Stripe details
     * @throws \Exception If Stripe price is invalid
     */
    private function fetchBoxDetailsFromStripe(array $boxes): array
    {
        $stripe = Cashier::stripe();
        $boxEntries = [];

        foreach ($boxes as $boxInput) {
            $stripePriceId = $boxInput['stripe_price_id'];
            $quantity = $boxInput['quantity'] ?? 1;

            try {
                $stripePrice = $stripe->prices->retrieve($stripePriceId, [
                    'expand' => ['product']
                ]);
            } catch (\Exception $e) {
                throw new \Exception("Invalid Stripe Price ID: {$stripePriceId}");
            }

            $boxEntries[] = [
                'stripe_price_id' => $stripePrice->id,
                'stripe_product_id' => $stripePrice->product->id,
                'box_size' => $stripePrice->product->metadata->type ?? null,
                'shipping' => $stripePrice->product->metadata->shipping ?? null,
                'box_name' => $stripePrice->product->name,
                'box_price' => $stripePrice->unit_amount / 100,
                'currency' => strtolower($stripePrice->currency),
                'quantity' => $quantity,
            ];
        }

        return $boxEntries;
    }

    /**
     * Save box entries to the database
     * 
     * @param Order $order The order to attach boxes to
     * @param array $boxEntries Array of box data from fetchBoxDetailsFromStripe
     * @return float Total box price
     */
    private function saveBoxEntries(Order $order, array $boxEntries): float
    {
        $totalBoxPrice = 0;

        foreach ($boxEntries as $entry) {
            OrderBox::create([
                'order_id' => $order->id,
                'stripe_price_id' => $entry['stripe_price_id'],
                'stripe_product_id' => $entry['stripe_product_id'],
                'box_size' => $entry['box_size'],
                'box_name' => $entry['box_name'],
                'box_price' => $entry['box_price'],
                'currency' => $entry['currency'],
                'quantity' => $entry['quantity'],
            ]);

            $totalBoxPrice += $entry['box_price'] * $entry['quantity'];
        }

        // Update order with total and legacy fields
        $updateData = ['box_price' => $totalBoxPrice];

        if (count($boxEntries) === 1) {
            $singleBox = $boxEntries[0];
            $updateData['box_size'] = $singleBox['box_size'];
            $updateData['stripe_price_id'] = $singleBox['stripe_price_id'];
            $updateData['stripe_product_id'] = $singleBox['stripe_product_id'];
        } else {
            $updateData['box_size'] = null;
            $updateData['stripe_price_id'] = null;
            $updateData['stripe_product_id'] = null;
        }

        $order->update($updateData);

        return $totalBoxPrice;
    }

    /**
     * Delete an order completely (admin only)
     */
    public function deleteOrder(Request $request, Order $order)
    {
        DB::beginTransaction();

        try {
            $orderNumber = $order->order_number;
            $userId = $order->user_id;
            
            // Delete all boxes
            $order->boxes()->delete();
            
            // Delete all items first (this will trigger model events to delete files)
            $order->items()->each(function ($item) {
                $item->delete();
            });
            
            // Delete GIA file if exists
            if ($order->gia_path) {
                $order->deleteGia();
            }
            
            // Delete the order
            $order->delete();

            DB::commit();

            Log::info('Admin deleted order', [
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order '{$orderNumber}' has been deleted successfully"
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin failed to delete order', [
                'order_id' => $order->id,
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Add item to any order (regardless of status)
     * Supports file uploads for proof_of_purchase and product_image
     */
    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'product_url' => 'nullable|url|max:1000',
            'quantity' => 'required|integer|min:1|max:999',
            'declared_value' => 'nullable|numeric|min:0|max:99999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => 'nullable|string|in:' . implode(',', array_keys(OrderItem::CARRIERS)),
            'merchant_order_id' => 'nullable|string|max:255',
            'estimated_delivery_date' => 'nullable|date',
            'arrived' => 'boolean',
            'weight' => 'nullable|numeric|min:0.01|max:999.99',
            'dimensions' => 'nullable|array',
            'dimensions.length' => 'nullable|numeric|min:0|max:999',
            'dimensions.width' => 'nullable|numeric|min:0|max:999',
            'dimensions.height' => 'nullable|numeric|min:0|max:999',
            'product_image_url' => 'nullable|url|max:1000',
            // File uploads
            'proof_of_purchase' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'product_image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
        ]);

        DB::beginTransaction();

        try {
            $user = $order->user;
            $userName = Str::slug($user->name);

            // Create the order item with all fields
            $itemData = $request->only([
                'product_name', 'product_url', 'quantity', 'declared_value',
                'tracking_number', 'tracking_url', 'carrier', 'merchant_order_id',
                'estimated_delivery_date', 'weight', 'dimensions', 'product_image_url'
            ]);

            $item = $order->items()->create($itemData);

            // Auto-detect retailer and carrier if not provided
            if ($item->product_url && !$item->retailer) {
                $item->retailer = $item->extractRetailer();
            }
            if (!$item->carrier && $item->tracking_number) {
                $item->carrier = $item->detectCarrier();
            }
            if ($request->boolean('arrived')) {
                $item->arrived = true;
                $item->arrived_at = now();
            }

            // Handle Proof of Purchase Upload
            if ($request->hasFile('proof_of_purchase')) {
                $file = $request->file('proof_of_purchase');
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/proof";

                $extension = $file->getClientOriginalExtension();
                $filename = "proof-" . time() . ".{$extension}";

                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url = config('filesystems.disks.spaces.url') . '/' . $path;

                $item->update([
                    'proof_of_purchase_path' => $path,
                    'proof_of_purchase_filename' => $file->getClientOriginalName(),
                    'proof_of_purchase_mime_type' => $file->getClientMimeType(),
                    'proof_of_purchase_size' => $file->getSize(),
                    'proof_of_purchase_url' => $url,
                ]);
            }

            // Handle Product Image Upload
            if ($request->hasFile('product_image')) {
                $imgFile = $request->file('product_image');
                $imgStoragePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/image";

                $imgExt = $imgFile->getClientOriginalExtension();
                $imgFilename = "product-" . time() . ".{$imgExt}";

                $imgPath = Storage::disk('spaces')->putFileAs($imgStoragePath, $imgFile, $imgFilename, 'public');
                $imgUrl = config('filesystems.disks.spaces.url') . '/' . $imgPath;

                $item->update([
                    'product_image_path' => $imgPath,
                    'product_image_filename' => $imgFile->getClientOriginalName(),
                    'product_image_mime_type' => $imgFile->getClientMimeType(),
                    'product_image_size' => $imgFile->getSize(),
                    'product_image_url' => $imgUrl,
                ]);
            }

            $item->save();

            // Recalculate order totals
            $order->update([
                'total_weight' => $order->calculateTotalWeight(),
                'declared_value' => $order->calculateTotalDeclaredValue(),
                'iva_amount' => $order->calculateIVA()
            ]);

            DB::commit();

            Log::info('Admin added item to order', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'admin_id' => $request->user()->id,
                'has_proof' => $request->hasFile('proof_of_purchase'),
                'has_image' => $request->hasFile('product_image'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item added successfully',
                'data' => [
                    'item' => $item->fresh(),
                    'order' => $order->fresh()->load(['items', 'boxes'])
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Admin failed to add item', [
                'order_id' => $order->id,
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add item',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update any item (admin override)
     * Supports file uploads/removal for proof_of_purchase and product_image
     */
    public function updateItem(Request $request, Order $order, OrderItem $item)
    {
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order'
            ], 404);
        }

        $request->validate([
            'product_name' => 'nullable|string|max:255',
            'product_url' => 'nullable|url|max:1000',
            'quantity' => 'nullable|integer|min:1|max:999',
            'declared_value' => 'nullable|numeric|min:0|max:99999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => 'nullable|string|in:' . implode(',', array_keys(OrderItem::CARRIERS)),
            'merchant_order_id' => 'nullable|string|max:255',
            'estimated_delivery_date' => 'nullable|date',
            'arrived' => 'nullable|boolean',
            'arrived_at' => 'nullable|date',
            'weight' => 'nullable|numeric|min:0.01|max:999.99',
            'dimensions' => 'nullable|array',
            'dimensions.length' => 'nullable|numeric|min:0|max:999',
            'dimensions.width' => 'nullable|numeric|min:0|max:999',
            'dimensions.height' => 'nullable|numeric|min:0|max:999',
            'product_image_url' => 'nullable|url|max:1000',
            // File uploads
            'proof_of_purchase' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'product_image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
            // File removal flags
            'remove_proof_of_purchase' => 'nullable|boolean',
            'remove_product_image' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $user = $order->user;
            $userName = Str::slug($user->name);

            // Get update data excluding file fields and removal flags
            $updateData = $request->except([
                'proof_of_purchase', 'product_image',
                'remove_proof_of_purchase', 'remove_product_image'
            ]);

            // Convert empty strings to null for nullable fields
            $nullableFields = [
                'product_url', 'merchant_order_id', 'tracking_number',
                'tracking_url', 'carrier', 'estimated_delivery_date',
                'declared_value', 'product_image_url'
            ];

            foreach ($nullableFields as $field) {
                if (array_key_exists($field, $updateData) && ($updateData[$field] === '' || $updateData[$field] === null)) {
                    $updateData[$field] = null;
                }
            }

            // Update basic fields
            $item->update($updateData);

            // Handle arrival status
            if ($request->has('arrived')) {
                if ($request->boolean('arrived') && !$item->arrived_at) {
                    $item->arrived_at = $request->arrived_at ?? now();
                } elseif (!$request->boolean('arrived')) {
                    $item->arrived_at = null;
                }
                $item->save();
            }

            // Re-detect carrier if tracking number changed but carrier not provided
            if ($request->has('tracking_number') && !$request->has('carrier')) {
                $item->carrier = $item->detectCarrier();
                $item->save();
            }

            // Handle Proof of Purchase Deletion
            if ($request->boolean('remove_proof_of_purchase')) {
                $item->deleteProofOfPurchase();
            }

            // Handle Product Image Deletion
            if ($request->boolean('remove_product_image')) {
                $item->deleteProductImage();
                $item->update(['product_image_url' => null]);
            }

            // Handle Proof of Purchase Upload/Replacement
            if ($request->hasFile('proof_of_purchase')) {
                // Delete existing file first
                $item->deleteProofOfPurchase();

                $file = $request->file('proof_of_purchase');
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/proof";
                $filename = "proof-" . time() . "." . $file->getClientOriginalExtension();

                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url = config('filesystems.disks.spaces.url') . '/' . $path;

                $item->update([
                    'proof_of_purchase_path' => $path,
                    'proof_of_purchase_filename' => $file->getClientOriginalName(),
                    'proof_of_purchase_mime_type' => $file->getClientMimeType(),
                    'proof_of_purchase_size' => $file->getSize(),
                    'proof_of_purchase_url' => $url,
                ]);
            }

            // Handle Product Image Upload/Replacement
            if ($request->hasFile('product_image')) {
                // Delete existing file first
                $item->deleteProductImage();

                $imgFile = $request->file('product_image');
                $imgStoragePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/image";
                $imgFilename = "product-" . time() . "." . $imgFile->getClientOriginalExtension();

                $imgPath = Storage::disk('spaces')->putFileAs($imgStoragePath, $imgFile, $imgFilename, 'public');
                $imgUrl = config('filesystems.disks.spaces.url') . '/' . $imgPath;

                $item->update([
                    'product_image_path' => $imgPath,
                    'product_image_filename' => $imgFile->getClientOriginalName(),
                    'product_image_mime_type' => $imgFile->getClientMimeType(),
                    'product_image_size' => $imgFile->getSize(),
                    'product_image_url' => $imgUrl,
                ]);
            }

            // Recalculate order totals
            $order->update([
                'total_weight' => $order->calculateTotalWeight(),
                'declared_value' => $order->calculateTotalDeclaredValue(),
                'iva_amount' => $order->calculateIVA()
            ]);

            // Check and update order status if needed
            $order->checkAndUpdatePackageStatus();

            DB::commit();

            Log::info('Admin updated item', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'admin_id' => $request->user()->id,
                'fields_updated' => array_keys($request->all()),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item updated successfully',
                'data' => [
                    'item' => $item->fresh(),
                    'order' => $order->fresh()->load(['items', 'boxes'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Admin failed to update item', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update item',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete any item (admin override)
     */
    public function deleteItem(Request $request, Order $order, OrderItem $item)
    {
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $item->delete();

            // Recalculate order totals
            $order->update([
                'total_weight' => $order->calculateTotalWeight(),
                'declared_value' => $order->calculateTotalDeclaredValue(),
                'iva_amount' => $order->calculateIVA()
            ]);

            DB::commit();

            Log::info('Admin deleted item', [
                'order_id' => $order->id,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item deleted successfully',
                'data' => $order->fresh()->load(['items', 'boxes'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete item',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}