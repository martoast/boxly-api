<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
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
     * Store a newly created order.
     */
    public function store(StoreOrderRequest $request)
    {
        $order = Order::create([
            'user_id' => $request->user()->id,
            'order_number' => Order::generateOrderNumber(),
            'tracking_number' => Order::generateTrackingNumber(),
            'delivery_address' => $request->delivery_address,
            'is_rural' => $request->is_rural ?? false,
            'status' => Order::STATUS_COLLECTING,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully',
            'data' => $order
        ], 201);
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
            'stripe_invoice_url' => $order->stripe_invoice_url,
        ];

        return response()->json([
            'success' => true,
            'data' => $trackingInfo
        ]);
    }

    /**
     * Pay for quoted order (redirect to Stripe invoice)
     */
    public function pay(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($order->status !== Order::STATUS_QUOTE_SENT || !$order->stripe_invoice_url) {
            return response()->json([
                'success' => false,
                'message' => 'No quote available for this order'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'invoice_url' => $order->stripe_invoice_url
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
        // Don't allow if packages have already arrived or quote has been sent
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
     */
    public function findBySession(Request $request, $sessionId)
    {
        // First check if order already exists
        $order = Order::where('stripe_checkout_session_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();
        
        if ($order) {
            return response()->json([
                'success' => true,
                'data' => $order->load('items')
            ]);
        }

        // If no order exists, create it from the Stripe session
        try {
            // Retrieve the session from Stripe
            $session = \Laravel\Cashier\Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent', 'line_items']
            ]);

            // Verify session belongs to this user
            if ($session->metadata->user_id != $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Check if payment was successful
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed',
                    'payment_status' => $session->payment_status
                ], 400);
            }

            // Parse metadata
            $metadata = $session->metadata;
            $deliveryAddress = json_decode($metadata->delivery_address, true);
            $declaredValue = floatval($metadata->declared_value);
            $ivaAmount = floatval($metadata->iva_amount);
            $isRural = $metadata->is_rural === 'true';
            
            // Calculate box price from line items (first item should be the box)
            $boxPrice = 0;
            $ruralSurcharge = 0;
            
            foreach ($session->line_items->data as $lineItem) {
                if (isset($lineItem->price->id) && $lineItem->price->id === $metadata->price_id) {
                    $boxPrice = $lineItem->amount_total / 100; // Convert from cents
                } elseif (strpos(strtolower($lineItem->description ?? ''), 'rural') !== false) {
                    $ruralSurcharge = $lineItem->amount_total / 100;
                }
            }
            
            // Create the order
            $order = Order::create([
                'user_id' => $request->user()->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_COLLECTING,
                'box_size' => $metadata->box_type,
                'box_price' => $boxPrice,
                'declared_value' => $declaredValue,
                'iva_amount' => $ivaAmount,
                'is_rural' => $isRural,
                'rural_surcharge' => $isRural ? $ruralSurcharge : null,
                'delivery_address' => $deliveryAddress,
                'amount_paid' => $session->amount_total / 100, // Convert from cents
                'currency' => $session->currency,
                'stripe_product_id' => $metadata->product_id,
                'stripe_price_id' => $metadata->price_id,
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $session->payment_intent->id ?? $session->payment_intent,
                'paid_at' => now(),
            ]);

            \Illuminate\Support\Facades\Log::info('Order created from Stripe session', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'session_id' => $sessionId,
                'amount_paid' => $order->amount_paid
            ]);

            return response()->json([
                'success' => true,
                'data' => $order->load('items')
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error creating order from session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}