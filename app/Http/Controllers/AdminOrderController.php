<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Http\Requests\AdminShipOrderRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items']);

        if ($request->has('status')) {
            $query->status($request->status);
        }

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

    public function show(Order $order)
    {
        return response()->json([
            'success' => true,
            'data' => $order->load(['user', 'items'])
        ]);
    }

    public function readyToShip()
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

    public function updateStatus(AdminUpdateOrderStatusRequest $request, Order $order)
    {
        $data = ['status' => $request->status];

        switch ($request->status) {
            case Order::STATUS_PROCESSING:
                $data['processing_started_at'] = now();
                break;

            case Order::STATUS_AWAITING_PAYMENT:
                if (!$order->quote_sent_at) {
                    $data['quote_sent_at'] = now();
                    $data['quote_expires_at'] = now()->addDays(7);
                }
                break;

            case Order::STATUS_PAID:
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

    public function shipOrder(AdminShipOrderRequest $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PROCESSING) {
            return response()->json([
                'success' => false,
                'message' => 'Only orders in processing can be shipped'
            ], 400);
        }

        DB::beginTransaction();

        try {
            if ($request->hasFile('gia_file')) {
                $file = $request->file('gia_file');

                $user = $order->user;
                $userName = Str::slug($user->name);
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/shipping";

                $filename = "gia-" . time() . ".pdf";

                $path = Storage::disk('spaces')->putFileAs(
                    $storagePath,
                    $file,
                    $filename,
                    'public'
                );

                $url = config('filesystems.disks.spaces.url') . '/' . $path;

                $order->update([
                    'status' => Order::STATUS_SHIPPED,
                    'dhl_waybill_number' => $request->dhl_waybill_number,
                    'estimated_delivery_date' => $request->estimated_delivery_date,
                    'shipped_at' => now(),
                    'gia_path' => $path,
                    'gia_filename' => $file->getClientOriginalName(),
                    'gia_mime_type' => $file->getClientMimeType(),
                    'gia_size' => $file->getSize(),
                    'gia_url' => $url,
                ]);

                if ($request->has('notes')) {
                    $order->notes = ($order->notes ? $order->notes . "\n" : '') .
                        "Shipped: " . $request->notes;
                    $order->save();
                }
            }

            DB::commit();

            Log::info('Order shipped successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'dhl_waybill' => $order->dhl_waybill_number,
                'gia_path' => $path ?? null,
                'gia_url' => $order->gia_url,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order shipped successfully',
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items']),
                    'dhl_tracking_url' => $order->dhl_tracking_url,
                    'gia_url' => $order->gia_full_url,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($path)) {
                Storage::disk('spaces')->delete($path);
            }

            Log::error('Failed to ship order', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to ship order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function viewGia(Request $request, Order $order)
    {
        if (!$order->gia_path) {
            return response()->json([
                'success' => false,
                'message' => 'No GIA document found for this order'
            ], 404);
        }

        if ($order->gia_url) {
            return redirect($order->gia_full_url);
        }

        return response()->json([
            'success' => false,
            'message' => 'GIA document URL not available'
        ], 404);
    }

    public function destroy(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_COLLECTING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order that has been completed. Only orders still adding products can be deleted.'
            ], 400);
        }

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

            $order->items()->each(function ($item) {
                $item->delete();
            });

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

    public function dashboard()
    {
        $stats = [
            'orders' => [
                'total' => Order::count(),
                'collecting' => Order::status(Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => Order::status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'packages_complete' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'processing' => Order::status(Order::STATUS_PROCESSING)->count(),
                'awaiting_payment' => Order::status(Order::STATUS_AWAITING_PAYMENT)->count(),
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
                    ->whereHas('order', function ($q) {
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
                'ready_to_ship' => Order::status(Order::STATUS_PROCESSING)->count(),
                'needs_invoicing' => Order::status(Order::STATUS_DELIVERED)->count(),
                'awaiting_payment' => Order::status(Order::STATUS_AWAITING_PAYMENT)->count(),
            ],
            'box_distribution' => [
                'extra-small' => Order::where('box_size', 'extra-small')->count(),
                'small' => Order::where('box_size', 'small')->count(),
                'medium' => Order::where('box_size', 'medium')->count(),
                'large' => Order::where('box_size', 'large')->count(),
                'extra-large' => Order::where('box_size', 'extra-large')->count(),
            ]
        ];

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

    public function byStatus($status)
    {
        $validStatuses = [
            Order::STATUS_COLLECTING,
            Order::STATUS_AWAITING_PACKAGES,
            Order::STATUS_PACKAGES_COMPLETE,
            Order::STATUS_PROCESSING,
            Order::STATUS_AWAITING_PAYMENT,
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
            case Order::STATUS_AWAITING_PAYMENT:
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