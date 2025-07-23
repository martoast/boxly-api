<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminMarkItemArrivedRequest;
use App\Http\Requests\AdminUpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class AdminOrderItemController extends Controller
{

    /**
     * Display a listing of all packages (items).
     */
    public function index(Request $request)
    {
        $query = OrderItem::with(['order.user']);

        // Filter by status (arrived/pending)
        if ($request->has('status')) {
            if ($request->status === 'arrived') {
                $query->where('arrived', true);
            } elseif ($request->status === 'pending') {
                $query->where('arrived', false);
            }
        }

        // Filter by missing weight
        if ($request->has('missing_weight') && $request->missing_weight) {
            $query->where('arrived', true)->whereNull('weight');
        }

        // Filter by search term
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")
                ->orWhere('carrier', 'like', "%{$search}%")
                ->orWhereHas('order', function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%");
                })
                ->orWhereHas('order.user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Sort by latest or by arrived_at if filtering by arrived
        if ($request->has('status') && $request->status === 'arrived') {
            $query->latest('arrived_at');
        } else {
            $query->latest();
        }

        $packages = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $packages
        ]);
    }

    /**
     * Display the specified package item.
     */
    public function show(OrderItem $item)
    {
        $item->load(['order.user']);

        return response()->json([
            'success' => true,
            'data' => $item
        ]);
    }

    /**
     * Update package item details (admin can edit any field)
     */
    public function update(AdminUpdateOrderItemRequest $request, OrderItem $item)
    {
        // Get the original values for tracking changes
        $originalDeclaredValue = $item->declared_value;
        $originalWeight = $item->weight;
        
        // Update the item with validated data
        $item->update($request->validated());

        // If carrier is not provided but tracking number changed, try to detect it
        if ($request->has('tracking_number') && !$request->has('carrier')) {
            $item->carrier = $item->detectCarrier();
            $item->save();
        }

        // If declared value changed, we need to recalculate IVA for the order
        if ($request->has('declared_value') && $originalDeclaredValue != $item->declared_value) {
            $this->recalculateOrderIVA($item->order);
        }

        // If weight changed and all items are weighed, update order total weight
        if ($request->has('weight') && $originalWeight != $item->weight) {
            $item->order->update([
                'total_weight' => $item->order->calculateTotalWeight()
            ]);
        }

        // Check if order status needs updating
        $item->order->checkAndUpdatePackageStatus();

        return response()->json([
            'success' => true,
            'message' => 'Package details updated successfully',
            'data' => $item->fresh()->load(['order.user'])
        ]);
    }

    /**
     * Get all pending packages (awaiting arrival)
     */
    public function pending(Request $request)
    {
        $query = OrderItem::with(['order.user'])
            ->where('arrived', false);

        // Filter by tracking number
        if ($request->has('tracking')) {
            $query->where('tracking_number', 'like', "%{$request->tracking}%");
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        $items = $query->oldest()->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * Mark item as arrived with weight
     */
    public function markArrived(AdminMarkItemArrivedRequest $request, Order $order, OrderItem $item)
    {
        // Verify item belongs to order
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order'
            ], 404);
        }

        // Store original declared value for tracking changes
        $originalDeclaredValue = $item->declared_value;

        if ($request->arrived) {
            $item->arrived = true;
            $item->arrived_at = now();
            
            if ($request->has('weight')) {
                $item->weight = $request->weight;
            }
            
            if ($request->has('dimensions')) {
                $item->dimensions = $request->dimensions;
            }
            
            if ($request->has('declared_value')) {
                $item->declared_value = $request->declared_value;
            }
        } else {
            $item->arrived = false;
            $item->arrived_at = null;
            $item->weight = null;
            $item->dimensions = null;
            // Note: We don't reset declared_value when marking as not arrived
        }

        $item->save();

        // If declared value changed, recalculate IVA for the order
        if ($request->has('declared_value') && $originalDeclaredValue != $item->declared_value) {
            $this->recalculateOrderIVA($order);
        }

        // Check if all items have arrived and update order status
        $order->checkAndUpdatePackageStatus();

        // Update order's total weight
        $order->update([
            'total_weight' => $order->calculateTotalWeight()
        ]);

        return response()->json([
            'success' => true,
            'message' => $request->arrived ? 'Item marked as arrived' : 'Item marked as not arrived',
            'data' => [
                'item' => $item->fresh(),
                'order' => $order->fresh()->load('items')
            ]
        ]);
    }
    /**
     * Get items missing weight measurements
     */
    public function missingWeight()
    {
        $items = OrderItem::with(['order.user'])
            ->where('arrived', true)
            ->whereNull('weight')
            ->oldest('arrived_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * Recalculate order IVA based on all items' declared values
     */
    private function recalculateOrderIVA(Order $order)
    {
        // Sum all declared values from items
        $totalDeclaredValue = $order->items()->sum('declared_value');
        
        // IVA only applies when declared value is $50 USD or more
        $ivaAmount = 0;
        if ($totalDeclaredValue >= 50) {
            $ivaAmount = round($totalDeclaredValue * 0.16, 2);
        }
        
        // Update order with new values
        $order->update([
            'declared_value' => $totalDeclaredValue,
            'iva_amount' => $ivaAmount
        ]);
    }
}