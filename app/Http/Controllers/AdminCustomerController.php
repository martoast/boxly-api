<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $query = User::withCount(['orders', 'activeOrders'])
            ->where('role', 'customer');

        // Filter by search term
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by customers with active orders
        if ($request->has('active_only') && $request->active_only) {
            $query->has('activeOrders');
        }

        $customers = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $customers
        ]);
    }

    /**
     * Display the specified customer.
     */
    public function show(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $customer->loadCount(['orders', 'activeOrders']);
        $customer->load(['orders' => function ($query) {
            $query->latest()->limit(5);
        }]);

        // Calculate customer stats
        $stats = [
            'total_spent' => $customer->orders()->sum('amount_paid'),
            'total_orders' => $customer->orders_count,
            'active_orders' => $customer->active_orders_count,
            'average_order_value' => $customer->orders()->avg('amount_paid') ?? 0,
            'member_since' => $customer->created_at->diffForHumans(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Get customer's orders
     */
    public function orders(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $orders = $customer->orders()
            ->with('items')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get customer's current collecting orders
     */
    public function collectingOrders(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $orders = $customer->collectingOrders()
            ->with('items')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}