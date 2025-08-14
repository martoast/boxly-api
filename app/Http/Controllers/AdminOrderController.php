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
     * Get orders ready to ship (paid orders)
     */
    public function readyToShip()
    {
        $orders = Order::with(['user', 'items'])
            ->status(Order::STATUS_PAID)
            ->oldest('paid_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get orders ready for processing (all packages arrived)
     */
    public function readyToProcess()
    {
        $orders = Order::with(['user', 'items'])
            ->status(Order::STATUS_PACKAGES_COMPLETE)
            ->oldest('updated_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get orders needing quotes
     */
    public function needingQuotes()
    {
        $orders = Order::with(['user', 'items'])
            ->status(Order::STATUS_PROCESSING)
            ->oldest('processing_started_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Update order status
     */
    public function updateStatus(AdminUpdateOrderStatusRequest $request, Order $order)
    {
        $data = ['status' => $request->status];

        // Handle status-specific updates
        switch ($request->status) {
            case Order::STATUS_PROCESSING:
                $data['processing_started_at'] = now();
                break;
                
            case Order::STATUS_QUOTE_SENT:
                // Quote sending is handled in a separate controller
                // This is just for manual status updates if needed
                if (!$order->quote_sent_at) {
                    $data['quote_sent_at'] = now();
                    $data['quote_expires_at'] = now()->addDays(7);
                }
                break;
                
            case Order::STATUS_PAID:
                // Payment is usually handled via webhook
                // This is for manual marking if needed
                if (!$order->paid_at) {
                    $data['paid_at'] = now();
                }
                break;
                
            case Order::STATUS_SHIPPED:
                $data['estimated_delivery_date'] = $request->estimated_delivery_date;
                $data['shipped_at'] = now();
                break;
                
            case Order::STATUS_DELIVERED:
                $data['actual_delivery_date'] = now();
                $data['delivered_at'] = now();
                break;
                
            case Order::STATUS_CANCELLED:
                // Optional: Add cancellation reason if needed
                if ($request->has('notes')) {
                    $data['notes'] = $order->notes . "\nCancelled: " . $request->notes;
                }
                break;
        }

        $order->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh()->load(['user', 'items'])
        ]);
    }

    /**
     * Delete an order (admin can delete if still collecting and no packages arrived)
     */
    public function destroy(Request $request, Order $order)
    {
        // Only allow deletion if order is still collecting
        if ($order->status !== Order::STATUS_COLLECTING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order that has been completed. Only orders still adding products can be deleted.'
            ], 400);
        }

        // Don't allow deletion if any packages have arrived
        if ($order->arrivedItems()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order with packages that have already arrived at the warehouse.'
            ], 400);
        }

        DB::beginTransaction();
        
        try {
            $orderNumber = $order->order_number;
            $trackingNumber = $order->tracking_number;
            $userId = $order->user_id;
            $userEmail = $order->user->email;
            
            // Delete all items first (this will trigger the model event to delete proof of purchase files)
            $order->items()->each(function ($item) {
                $item->delete();
            });
            
            // Now delete the order
            $order->delete();
            
            DB::commit();
        
            return response()->json([
                'success' => true,
                'message' => "Order '{$orderNumber}' has been deleted successfully."
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
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
                'processing' => Order::status(Order::STATUS_PROCESSING)->count(),
                'quote_sent' => Order::status(Order::STATUS_QUOTE_SENT)->count(),
                'paid' => Order::status(Order::STATUS_PAID)->count(),
                'shipped' => Order::status(Order::STATUS_SHIPPED)->count(),
                'delivered' => Order::status(Order::STATUS_DELIVERED)->count(),
                'cancelled' => Order::status(Order::STATUS_CANCELLED)->count(),
            ],
            'revenue' => [
                'today' => Order::whereDate('paid_at', today())->sum('amount_paid'),
                'this_week' => Order::whereBetween('paid_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('amount_paid'),
                'this_month' => Order::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount_paid'),
                'total' => Order::sum('amount_paid'),
            ],
            'packages' => [
                'awaiting_arrival' => \App\Models\OrderItem::where('arrived', false)
                    ->whereHas('order', function($q) {
                        $q->whereIn('status', [
                            Order::STATUS_AWAITING_PACKAGES,
                            Order::STATUS_PACKAGES_COMPLETE
                        ]);
                    })
                    ->count(),
                'arrived_today' => \App\Models\OrderItem::whereDate('arrived_at', today())->count(),
                'missing_weight' => \App\Models\OrderItem::where('arrived', true)->whereNull('weight')->count(),
            ],
            'actions_needed' => [
                'ready_to_process' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'needs_quote' => Order::status(Order::STATUS_PROCESSING)->count(),
                'awaiting_payment' => Order::status(Order::STATUS_QUOTE_SENT)->count(),
                'ready_to_ship' => Order::status(Order::STATUS_PAID)->count(),
            ],
            'box_distribution' => [
                'extra-small' => Order::where('box_size', 'extra-small')->count(),
                'small' => Order::where('box_size', 'small')->count(),
                'medium' => Order::where('box_size', 'medium')->count(),
                'large' => Order::where('box_size', 'large')->count(),
                'extra-large' => Order::where('box_size', 'extra-large')->count(),
            ]
        ];

        // Add recent activity
        $stats['recent_activity'] = [
            'orders_completed_today' => Order::whereDate('completed_at', today())->count(),
            'quotes_sent_today' => Order::whereDate('quote_sent_at', today())->count(),
            'payments_received_today' => Order::whereDate('paid_at', today())->count(),
            'orders_shipped_today' => Order::whereDate('shipped_at', today())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get orders by status for admin dashboard
     */
    public function byStatus($status)
    {
        $validStatuses = [
            Order::STATUS_COLLECTING,
            Order::STATUS_AWAITING_PACKAGES,
            Order::STATUS_PACKAGES_COMPLETE,
            Order::STATUS_PROCESSING,
            Order::STATUS_QUOTE_SENT,
            Order::STATUS_PAID,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
        ];

        if (!in_array($status, $validStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status'
            ], 400);
        }

        $query = Order::with(['user', 'items'])->status($status);

        // Order by relevant timestamp for each status
        switch ($status) {
            case Order::STATUS_COLLECTING:
                $query->latest('created_at');
                break;
            case Order::STATUS_AWAITING_PACKAGES:
                $query->oldest('completed_at');
                break;
            case Order::STATUS_PACKAGES_COMPLETE:
                $query->oldest('updated_at');
                break;
            case Order::STATUS_PROCESSING:
                $query->oldest('processing_started_at');
                break;
            case Order::STATUS_QUOTE_SENT:
                $query->oldest('quote_sent_at');
                break;
            case Order::STATUS_PAID:
                $query->oldest('paid_at');
                break;
            case Order::STATUS_SHIPPED:
                $query->latest('shipped_at');
                break;
            case Order::STATUS_DELIVERED:
                $query->latest('delivered_at');
                break;
            case Order::STATUS_CANCELLED:
                $query->latest('updated_at');
                break;
        }

        $orders = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}