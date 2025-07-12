<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminSendQuoteRequest;
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
                  ->orWhere('order_name', 'like', "%{$search}%")
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
     * Get orders ready to quote
     */
    public function readyToQuote()
    {
        $orders = Order::with(['user', 'items'])
            ->readyToQuote()
            ->oldest('completed_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Send quote to customer
     */
    public function sendQuote(AdminSendQuoteRequest $request, Order $order)
    {
        try {
            DB::beginTransaction();

            // Calculate pricing
            $weight = $order->calculateTotalWeight();
            $boxSize = $order->determineBoxSize();
            
            if (!$boxSize) {
                throw new \Exception('Package too heavy for available box sizes');
            }

            $shippingCost = $order->calculateShippingCost();
            $ivaAmount = $order->calculateIvaAmount();
            $totalAmount = $shippingCost + $ivaAmount;

            // Create Stripe Invoice
            $invoice = $order->user->invoiceFor(
                "Consolidation Order: {$order->order_name}",
                $shippingCost * 100, // Convert to cents
                [
                    'description' => "Box size: {$boxSize}, Weight: {$weight}kg",
                    'metadata' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'box_size' => $boxSize,
                        'weight' => $weight
                    ]
                ]
            );

            // Add IVA as line item
            if ($ivaAmount > 0) {
                $declaredTotal = $order->items->sum(function ($item) {
                    return $item->declared_value * $item->quantity;
                });
                
                $invoice->tab(
                    "IVA (16% of \${$declaredTotal} USD declared value)",
                    $ivaAmount * 100 // Convert to cents
                );
            }

            // Finalize and send invoice
            $invoice->sendInvoice();

            // Update order
            $order->update([
                'status' => Order::STATUS_QUOTE_SENT,
                'recommended_box_size' => $boxSize,
                'total_weight' => $weight,
                'stripe_invoice_id' => $invoice->id,
                'stripe_invoice_url' => $invoice->hosted_invoice_url,
                'quote_sent_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quote sent successfully',
                'data' => [
                    'order' => $order->fresh(),
                    'invoice_url' => $invoice->hosted_invoice_url,
                    'breakdown' => [
                        'shipping' => $shippingCost,
                        'iva' => $ivaAmount,
                        'total' => $totalAmount,
                        'currency' => 'MXN'
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error sending quote: ' . $e->getMessage()
            ], 500);
        }
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
                'awaiting_packages' => Order::status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'ready_to_quote' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'quote_sent' => Order::status(Order::STATUS_QUOTE_SENT)->count(),
                'paid' => Order::status(Order::STATUS_PAID)->count(),
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
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}