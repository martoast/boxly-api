<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    /**
     * Display a listing of all orders.
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items']);

        // Filter by status
        if ($request->has('status')) {
            $query->status($request->status);
        }

        // Filter by search term
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('tracking_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Display the specified order.
     */
    public function show(Order $order)
    {
        return response()->json([
            'success' => true,
            'data' => $order->load(['user', 'items'])
        ]);
    }

    /**
     * Get orders ready to ship (all packages arrived and weighed)
     */
    public function readyToShip()
    {
        $orders = Order::with(['user', 'items'])
            ->status(Order::STATUS_PACKAGES_COMPLETE)
            ->oldest('completed_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Update order status (ship or mark delivered)
     */
    public function updateStatus(AdminUpdateOrderStatusRequest $request, Order $order)
    {
        $data = ['status' => $request->status];

        if ($request->status === Order::STATUS_SHIPPED) {
            $data['tracking_number'] = $request->tracking_number;
            $data['estimated_delivery_date'] = $request->estimated_delivery_date;
            $data['shipped_at'] = now();
        }

        if ($request->status === Order::STATUS_DELIVERED) {
            $data['actual_delivery_date'] = now();
            $data['delivered_at'] = now();
        }

        $order->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh()->load(['user', 'items'])
        ]);
    }

    /**
     * Get dashboard statistics
     */
    public function dashboard()
    {
        $stats = [
            'orders' => [
                'total' => Order::count(),
                'collecting' => Order::status(Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => Order::status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'packages_complete' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'in_transit' => Order::status(Order::STATUS_SHIPPED)->count(),
                'delivered' => Order::status(Order::STATUS_DELIVERED)->count(),
            ],
            'revenue' => [
                'today' => Order::whereDate('paid_at', today())->sum('amount_paid'),
                'this_week' => Order::whereBetween('paid_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('amount_paid'),
                'this_month' => Order::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount_paid'),
                'total' => Order::sum('amount_paid'),
            ],
            'packages' => [
                'awaiting_arrival' => \App\Models\OrderItem::where('arrived', false)->count(),
                'arrived_today' => \App\Models\OrderItem::whereDate('arrived_at', today())->count(),
                'missing_weight' => \App\Models\OrderItem::where('arrived', true)->whereNull('weight')->count(),
            ],
            'box_distribution' => [
                'small' => Order::where('box_size', 'small')->count(),
                'medium' => Order::where('box_size', 'medium')->count(),
                'large' => Order::where('box_size', 'large')->count(),
                'xl' => Order::where('box_size', 'xl')->count(),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}