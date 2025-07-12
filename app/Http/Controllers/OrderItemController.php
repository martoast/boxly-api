<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderItemRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderItemController extends Controller
{
    /**
     * Store a new item in the order
     */
    public function store(StoreOrderItemRequest $request, Order $order)
    {
        $item = $order->items()->create([
            'product_url' => $request->product_url,
            'product_name' => $request->product_name,
            'quantity' => $request->quantity,
            'declared_value' => $request->declared_value,
            'tracking_number' => $request->tracking_number,
            'tracking_url' => $request->tracking_url,
            'carrier' => $request->carrier,
        ]);

        // Auto-detect retailer and carrier if not provided
        if (!$item->retailer) {
            $item->retailer = $item->extractRetailer();
        }
        
        if (!$item->carrier && $item->tracking_number) {
            $item->carrier = $item->detectCarrier();
        }
        
        $item->save();

        // TODO: Scrape product info from URL in background job
        // dispatch(new ScrapeProductInfo($item));

        return response()->json([
            'success' => true,
            'message' => 'Item added to order',
            'data' => $item
        ], 201);
    }

    /**
     * Update an item in the order
     */
    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item)
    {
        $item->update($request->validated());

        // Re-detect carrier if tracking number changed
        if ($request->has('tracking_number') && !$request->has('carrier')) {
            $item->carrier = $item->detectCarrier();
            $item->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Item updated successfully',
            'data' => $item->fresh()
        ]);
    }

    /**
     * Remove an item from the order
     */
    public function destroy(Request $request, Order $order, OrderItem $item)
    {
        // Check authorization
        if ($order->user_id !== $request->user()->id || 
            $order->status !== Order::STATUS_COLLECTING ||
            $item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from order'
        ]);
    }
}