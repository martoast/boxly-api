<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'delivery_address' => 'required|array',
            'delivery_address.street' => 'required|string|max:255',
            'delivery_address.exterior_number' => 'required|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'required|string|max:100',
            'delivery_address.municipio' => 'required|string|max:100',
            'delivery_address.estado' => 'required|string|max:100',
            'delivery_address.postal_code' => 'required|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
            'is_rural' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        DB::beginTransaction();

        try {
            $user = User::find($request->user_id);
            
            // Create the order
            $order = new Order([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => $request->status ?? Order::STATUS_COLLECTING,
                'is_rural' => $request->is_rural ?? false,
                'delivery_address' => $request->delivery_address,
                'currency' => 'mxn',
                'notes' => $request->notes,
            ]);

            // Skip email notifications for admin-created orders
            $order->skipEmailNotifications = true;
            $order->save();

            DB::commit();

            Log::info('Admin created order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $user->id,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->fresh()->load(['user', 'items'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin failed to create order', [
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
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
            'box_size' => 'nullable|string|in:extra-small,small,medium,large,extra-large',
            'box_price' => 'nullable|numeric|min:0|max:99999.99',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99',
            'iva_amount' => 'nullable|numeric|min:0|max:99999.99',
            'is_rural' => 'nullable|boolean',
            'rural_surcharge' => 'nullable|numeric|min:0|max:9999.99',
            'delivery_address' => 'nullable|array',
            'delivery_address.street' => 'required_with:delivery_address|string|max:255',
            'delivery_address.exterior_number' => 'required_with:delivery_address|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'required_with:delivery_address|string|max:100',
            'delivery_address.municipio' => 'required_with:delivery_address|string|max:100',
            'delivery_address.estado' => 'required_with:delivery_address|string|max:100',
            'delivery_address.postal_code' => 'required_with:delivery_address|regex:/^\d{5}$/',
            'total_weight' => 'nullable|numeric|min:0|max:999.99',
            'actual_weight' => 'nullable|numeric|min:0|max:999.99',
            'shipping_cost' => 'nullable|numeric|min:0|max:99999.99',
            'handling_fee' => 'nullable|numeric|min:0|max:9999.99',
            'insurance_fee' => 'nullable|numeric|min:0|max:9999.99',
            'quoted_amount' => 'nullable|numeric|min:0|max:999999.99',
            'quote_breakdown' => 'nullable|array',
            'amount_paid' => 'nullable|numeric|min:0|max:999999.99',
            'currency' => 'nullable|string|in:mxn,usd',
            'notes' => 'nullable|string|max:2000',
            'paid_at' => 'nullable|date',
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
            'payment_link' => 'nullable|url|max:500',
        ]);

        DB::beginTransaction();

        try {
            // Skip email notifications for admin manual updates
            $order->skipEmailNotifications = true;
            
            // Update the order with any provided fields
            $order->update($request->all());

            DB::commit();

            Log::info('Admin updated order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'admin_id' => $request->user()->id,
                'fields_updated' => array_keys($request->all()),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order->fresh()->load(['user', 'items'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin failed to update order', [
                'order_id' => $order->id,
                'admin_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
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
     */
    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'product_url' => 'nullable|url|max:1000',
            'quantity' => 'required|integer|min:1|max:999',
            'declared_value' => 'nullable|numeric|min:0|max:99999.99',
            'tracking_number' => 'nullable|string|max:255',
            'carrier' => 'nullable|string|in:' . implode(',', array_keys(OrderItem::CARRIERS)),
            'arrived' => 'boolean',
            'weight' => 'nullable|numeric|min:0.01|max:999.99',
        ]);

        DB::beginTransaction();

        try {
            $item = $order->items()->create($request->all());

            // Auto-detect retailer and carrier if not provided
            if ($item->product_url && !$item->retailer) {
                $item->retailer = $item->extractRetailer();
            }
            if (!$item->carrier && $item->tracking_number) {
                $item->carrier = $item->detectCarrier();
            }
            if ($request->arrived) {
                $item->arrived_at = now();
            }
            $item->save();

            // Recalculate order totals
            if ($item->weight) {
                $order->update([
                    'total_weight' => $order->calculateTotalWeight()
                ]);
            }
            
            if ($item->declared_value) {
                $order->update([
                    'declared_value' => $order->calculateTotalDeclaredValue(),
                    'iva_amount' => $order->calculateIVA()
                ]);
            }

            DB::commit();

            Log::info('Admin added item to order', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item added successfully',
                'data' => [
                    'item' => $item->fresh(),
                    'order' => $order->fresh()->load('items')
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add item',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update any item (admin override)
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
            'carrier' => 'nullable|string|in:' . implode(',', array_keys(OrderItem::CARRIERS)),
            'arrived' => 'nullable|boolean',
            'weight' => 'nullable|numeric|min:0.01|max:999.99',
        ]);

        DB::beginTransaction();

        try {
            $item->update($request->all());
            
            if ($request->has('arrived') && $request->arrived && !$item->arrived_at) {
                $item->arrived_at = now();
                $item->save();
            }

            // Recalculate order totals
            $order->update([
                'total_weight' => $order->calculateTotalWeight(),
                'declared_value' => $order->calculateTotalDeclaredValue(),
                'iva_amount' => $order->calculateIVA()
            ]);

            DB::commit();

            Log::info('Admin updated item', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item updated successfully',
                'data' => [
                    'item' => $item->fresh(),
                    'order' => $order->fresh()->load('items')
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
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
                'data' => $order->fresh()->load('items')
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