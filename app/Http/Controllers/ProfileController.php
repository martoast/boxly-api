<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;
use App\Models\Order;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'has_complete_address' => $user->hasCompleteAddress(),
                'created_at' => $user->created_at,
                // Stats
                'total_orders' => $user->orders()->count(),
                'active_orders' => $user->activeOrders()->count(),
                'unpaid_quotes' => $user->hasUnpaidQuotes(),
            ]
        ]);
    }

    /**
     * Update the authenticated user's profile
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Get user's dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        $stats = [
            'orders' => [
                'collecting' => $user->orders()->status(Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => $user->orders()->status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'ready_to_ship' => $user->orders()->status(Order::STATUS_QUOTE_SENT)->count(),
                'in_transit' => $user->orders()->whereIn('status', [
                    Order::STATUS_SHIPPED
                ])->count(),
                'delivered' => $user->orders()->status(Order::STATUS_DELIVERED)->count(),
            ],
            'totals' => [
                'total_orders' => $user->orders()->count(),
                'total_spent' => $user->orders()->sum('amount_paid'),
                'total_items' => $user->orders()->withCount('items')->get()->sum('items_count'),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}