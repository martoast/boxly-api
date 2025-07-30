<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Display a listing of the user's orders.
     */
    public function index(Request $request)
    {
        $query = Order::with(['items'])
            ->forUser($request->user()->id);
        
        // Add search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where('order_number', 'like', '%' . $searchTerm . '%');
        }
        
        // Add status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        $orders = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Display the specified order.
     */
    public function show(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order->load('items');

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Update the specified order.
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        // Update the order with validated data
        $order->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully',
            'data' => $order->fresh()
        ]);
    }

    /**
     * Mark order as complete (ready for consolidation)
     */
    public function complete(CompleteOrderRequest $request, Order $order)
    {
        try {
            $order->markAsComplete();

            return response()->json([
                'success' => true,
                'message' => 'Order marked as complete. We\'ll notify you when your packages arrive.',
                'data' => $order->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete the specified order.
     */
    public function destroy(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow deletion if order is still in collecting status
        if ($order->status !== Order::STATUS_COLLECTING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order that has been completed'
            ], 400);
        }

        try {
            // Store order number for the response message
            $orderNumber = $order->order_number;
            
            // Delete the order (cascade will delete related items)
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => "Order '{$orderNumber}' has been deleted successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order'
            ], 500);
        }
    }

    /**
     * Get user's collecting orders (for adding items)
     */
    public function collecting(Request $request)
    {
        $orders = Order::with(['items'])
            ->forUser($request->user()->id)
            ->status(Order::STATUS_COLLECTING)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get order tracking info
     */
    public function tracking(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $trackingInfo = [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => Order::getStatuses()[$order->status] ?? 'Unknown',
            'tracking_number' => $order->tracking_number,
            'arrival_progress' => $order->arrival_progress,
            'items_arrived' => $order->arrivedItems()->count(),
            'items_total' => $order->items()->count(),
            'total_weight' => $order->total_weight,
            'estimated_delivery_date' => $order->estimated_delivery_date?->format('Y-m-d'),
            'actual_delivery_date' => $order->actual_delivery_date?->format('Y-m-d'),
        ];

        return response()->json([
            'success' => true,
            'data' => $trackingInfo
        ]);
    }

    /**
     * Reopen order for modifications (return to collecting status)
     */
    public function reopen(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow reopening if order is in awaiting_packages status
        // Don't allow if packages have already arrived
        if (!in_array($order->status, [Order::STATUS_AWAITING_PACKAGES])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify order in current status'
            ], 400);
        }

        try {
            // Reset order to collecting status
            $order->update([
                'status' => Order::STATUS_COLLECTING,
                'completed_at' => null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order reopened for modifications',
                'data' => $order->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reopen order'
            ], 500);
        }
    }

    /**
     * Get order by session after stripe checkout
     * The order should already exist from the webhook
     */
    public function findBySession(Request $request, $sessionId)
    {
        // Find the order by stripe checkout session ID
        $order = Order::where('stripe_checkout_session_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();
        
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found. It may still be processing. Please try again in a moment.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order->load('items')
        ]);
    }
}