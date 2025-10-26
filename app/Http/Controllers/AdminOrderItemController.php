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

        // NEW: Filter by estimated delivery date
        if ($request->has('estimated_date')) {
            $query->whereDate('estimated_delivery_date', $request->estimated_date);
        }

        // NEW: Filter by estimated delivery date range
        if ($request->has('estimated_from') && $request->has('estimated_to')) {
            $query->expectedBetween($request->estimated_from, $request->estimated_to);
        } elseif ($request->has('estimated_from')) {
            $query->whereDate('estimated_delivery_date', '>=', $request->estimated_from);
        } elseif ($request->has('estimated_to')) {
            $query->whereDate('estimated_delivery_date', '<=', $request->estimated_to);
        }

        // NEW: Filter overdue items
        if ($request->has('overdue') && $request->overdue) {
            $query->overdue();
        }

        // NEW: Filter arriving soon
        if ($request->has('arriving_soon')) {
            $days = $request->arriving_soon_days ?? 3;
            $query->arrivingSoon($days);
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

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if ($sortBy === 'estimated_delivery_date') {
            $query->orderBy('estimated_delivery_date', $sortOrder);
        } elseif ($sortBy === 'arrived_at') {
            $query->orderBy('arrived_at', $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        $packages = $query->paginate($request->get('per_page', 20));

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

        // NEW: Filter by estimated delivery date
        if ($request->has('estimated_date')) {
            $query->whereDate('estimated_delivery_date', $request->estimated_date);
        }

        // NEW: Filter overdue
        if ($request->has('overdue') && $request->overdue) {
            $query->overdue();
        }

        // NEW: Filter arriving soon
        if ($request->has('arriving_soon') && $request->arriving_soon) {
            $days = $request->arriving_soon_days ?? 3;
            $query->arrivingSoon($days);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        // Sort by estimated delivery date if available, otherwise created_at
        if ($request->has('sort_by_delivery')) {
            $query->orderByRaw('estimated_delivery_date IS NULL, estimated_delivery_date ASC');
        } else {
            $query->oldest();
        }

        $items = $query->paginate(50);

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

        // Check if order is in collecting status when trying to mark as arrived
        if ($request->arrived && $order->status === Order::STATUS_COLLECTING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot mark items as arrived. The user has not completed the order yet.'
            ], 422);
        }

        // Store original declared value for tracking changes
        $originalDeclaredValue = $item->declared_value;

        if ($request->arrived) {
            // First, update weight, dimensions, and declared_value if provided
            $updateData = [];
            
            if ($request->has('weight')) {
                $updateData['weight'] = $request->weight;
            }
            
            if ($request->has('dimensions')) {
                $updateData['dimensions'] = $request->dimensions;
            }
            
            if ($request->has('declared_value')) {
                $updateData['declared_value'] = $request->declared_value;
            }
            
            // Update these fields if there's data to update
            if (!empty($updateData)) {
                $item->update($updateData);
            }
            
            // Now call markAsArrived which handles the arrived status and email
            $item->markAsArrived();
            
        } else {
            // Mark as not arrived - use the existing method
            $item->markAsNotArrived();
            
            // Also clear weight and dimensions when marking as not arrived
            $item->update([
                'weight' => null,
                'dimensions' => null
            ]);
        }

        // If declared value changed, recalculate IVA for the order
        if ($request->has('declared_value') && $originalDeclaredValue != $item->declared_value) {
            $this->recalculateOrderIVA($order);
        }

        // Update order's total weight (this happens automatically in markAsArrived, 
        // but we need it for the not arrived case too)
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
     * NEW: Get items expected to arrive today
     */
    public function expectedToday()
    {
        $items = OrderItem::with(['order.user'])
            ->where('arrived', false)
            ->whereDate('estimated_delivery_date', today())
            ->orderBy('estimated_delivery_date', 'asc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * NEW: Get overdue items
     */
    public function overdue()
    {
        $items = OrderItem::with(['order.user'])
            ->overdue()
            ->orderBy('estimated_delivery_date', 'asc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * NEW: Get items arriving soon
     */
    public function arrivingSoon(Request $request)
    {
        $days = $request->get('days', 3);

        $items = OrderItem::with(['order.user'])
            ->arrivingSoon($days)
            ->orderBy('estimated_delivery_date', 'asc')
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

    /**
     * View proof of purchase for an item (admin access)
     */
    public function viewProof(Request $request, OrderItem $item)
    {
        $item->load(['order.user']);

        if (!$item->proof_of_purchase_path) {
            return response()->json([
                'success' => false,
                'message' => 'No proof of purchase file found'
            ], 404);
        }

        // Since files are public, redirect to the URL
        if ($item->proof_of_purchase_url) {
            return redirect($item->proof_of_purchase_url);
        }

        // Fallback if URL is not set
        return response()->json([
            'success' => false,
            'message' => 'File URL not available'
        ], 404);
    }
}