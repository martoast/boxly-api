<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    /**
     * Track an order by tracking number (public endpoint)
     */
    public function track(Request $request)
    {
        $request->validate([
            'tracking_number' => 'required|string'
        ]);

        $trackingNumber = strtoupper(trim($request->tracking_number));

        // Find order by tracking number
        $order = Order::where('tracking_number', $trackingNumber)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'No order found with this tracking number'
            ], 404);
        }

        // Prepare public tracking data (limited information)
        $trackingData = [
            'tracking_number' => $order->tracking_number,
            'status' => $order->status,
            'status_label' => Order::getStatuses()[$order->status] ?? 'Unknown',
            'created_at' => $order->created_at->format('Y-m-d'),
            'last_updated' => $order->updated_at->format('Y-m-d H:i:s'),
            'box_size' => $order->box_size,
            'item_count' => $order->items()->count(),
            'arrival_progress' => [
                'percentage' => $order->arrival_progress,
                'items_arrived' => $order->arrivedItems()->count(),
                'items_total' => $order->items()->count(),
            ]
        ];

        // Add status-specific information
        switch ($order->status) {
            case Order::STATUS_COLLECTING:
                $trackingData['message'] = 'Order is being prepared. Customer is adding items.';
                break;
                
            case Order::STATUS_AWAITING_PACKAGES:
                $trackingData['message'] = 'Waiting for packages to arrive at our warehouse.';
                $trackingData['completed_at'] = $order->completed_at?->format('Y-m-d');
                break;
                
            case Order::STATUS_PACKAGES_COMPLETE:
                $trackingData['message'] = 'All packages have arrived. Preparing for shipment.';
                $trackingData['packages_complete_at'] = $order->updated_at->format('Y-m-d');
                if ($order->total_weight) {
                    $trackingData['total_weight_kg'] = number_format($order->total_weight, 2);
                }
                break;
                
            case Order::STATUS_SHIPPED:
                $trackingData['message'] = 'Package has been shipped and is on its way.';
                $trackingData['shipped_at'] = $order->shipped_at?->format('Y-m-d');
                $trackingData['estimated_delivery'] = $order->estimated_delivery_date?->format('Y-m-d');
                break;
                
            case Order::STATUS_DELIVERED:
                $trackingData['message'] = 'Package has been delivered successfully.';
                $trackingData['delivered_at'] = $order->delivered_at?->format('Y-m-d');
                break;
        }

        // Add delivery city/state (but not full address for privacy)
        if ($order->delivery_address) {
            $trackingData['delivery_location'] = [
                'municipio' => $order->delivery_address['municipio'] ?? null,
                'estado' => $order->delivery_address['estado'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $trackingData
        ]);
    }

    /**
     * Simple form view for tracking (optional)
     */
    public function form()
    {
        // This would return a simple HTML form if you want a web interface
        // For now, just return instructions
        return response()->json([
            'success' => true,
            'message' => 'Send a POST request with tracking_number parameter to track your order',
            'endpoint' => url('/track'),
            'method' => 'POST',
            'parameters' => [
                'tracking_number' => 'string (required)'
            ]
        ]);
    }
}