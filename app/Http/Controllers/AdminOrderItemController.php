<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminMarkItemArrivedRequest;
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

        if ($request->arrived) {
            $item->arrived = true;
            $item->arrived_at = now();
            
            if ($request->has('weight')) {
                $item->weight = $request->weight;
            }
            
            if ($request->has('dimensions')) {
                $item->dimensions = $request->dimensions;
            }
        } else {
            $item->arrived = false;
            $item->arrived_at = null;
            $item->weight = null;
            $item->dimensions = null;
        }

        $item->save();

        // Check if all items have arrived and update order status
        $order->checkAndUpdatePackageStatus();

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
     * Bulk mark items as arrived (for scanning multiple packages)
     */
    public function bulkArrived(Request $request)
    {
        $request->validate([
            'tracking_numbers' => 'required|array',
            'tracking_numbers.*' => 'required|string',
            'weight' => 'nullable|numeric|min:0.01'
        ]);

        $results = [];
        $defaultWeight = $request->weight;

        foreach ($request->tracking_numbers as $trackingNumber) {
            $item = OrderItem::where('tracking_number', $trackingNumber)
                ->where('arrived', false)
                ->first();

            if ($item) {
                $item->markAsArrived();
                
                if ($defaultWeight) {
                    $item->weight = $defaultWeight;
                    $item->save();
                }

                $results[] = [
                    'tracking_number' => $trackingNumber,
                    'success' => true,
                    'order_number' => $item->order->order_number,
                    'customer' => $item->order->user->name
                ];
            } else {
                $results[] = [
                    'tracking_number' => $trackingNumber,
                    'success' => false,
                    'message' => 'Package not found or already arrived'
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results
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
}