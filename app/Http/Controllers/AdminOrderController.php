<?php
namespace App\Http\Controllers;
use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Http\Requests\AdminShipOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
class AdminOrderController extends Controller
{
public function index(Request $request)
{
// Validate per_page parameter
$request->validate([
'per_page' => 'nullable|integer|min:1|max:500',
'limit' => 'nullable|integer|min:1|max:500',
]);
    // Use per_page or limit, default to 20
    $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

    $query = Order::with(['user', 'items']);

    if ($request->has('status')) {
        $query->status($request->status);
    }

    // Filter by orders with items expected to arrive by date
    if ($request->has('items_expected_by')) {
        $query->whereHas('items', function ($q) use ($request) {
            $q->expectedBy($request->items_expected_by);
        });
    }

    // Filter by orders with overdue items
    if ($request->has('has_overdue_items') && $request->has_overdue_items) {
        $query->whereHas('items', function ($q) {
            $q->overdue();
        });
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

    $orders = $query->latest()->paginate($perPage);

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

public function readyToShip(Request $request)
{
    $request->validate([
        'per_page' => 'nullable|integer|min:1|max:500',
        'limit' => 'nullable|integer|min:1|max:500',
    ]);

    $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

    $orders = Order::with(['user', 'items'])
        ->status(Order::STATUS_PROCESSING)
        ->oldest('processing_started_at')
        ->paginate($perPage);

    return response()->json([
        'success' => true,
        'data' => $orders
    ]);
}

public function readyToProcess(Request $request)
{
    $request->validate([
        'per_page' => 'nullable|integer|min:1|max:500',
        'limit' => 'nullable|integer|min:1|max:500',
    ]);

    $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

    $orders = Order::with(['user', 'items'])
        ->status(Order::STATUS_PACKAGES_COMPLETE)
        ->oldest('updated_at')
        ->paginate($perPage);

    return response()->json([
        'success' => true,
        'data' => $orders
    ]);
}

public function needingQuotes(Request $request)
{
    $request->validate([
        'per_page' => 'nullable|integer|min:1|max:500',
        'limit' => 'nullable|integer|min:1|max:500',
    ]);

    $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

    $orders = Order::with(['user', 'items'])
        ->status(Order::STATUS_PROCESSING)
        ->oldest('processing_started_at')
        ->paginate($perPage);

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

    // ✅ Skip email notifications for manual admin status changes
    // This prevents customers from being spammed with emails when admin is manually managing orders
    $order->skipEmailNotifications = true;
    $order->update($data);

    Log::info('Admin manually updated order status (no email sent)', [
        'order_id' => $order->id,
        'order_number' => $order->order_number,
        'previous_status' => $order->getOriginal('status'),
        'new_status' => $request->status,
        'admin_id' => $request->user()->id,
    ]);

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
    DB::beginTransaction();

    try {
        $orderNumber = $order->order_number;
        $trackingNumber = $order->tracking_number;
        $userId = $order->user_id;
        $userEmail = $order->user->email;

        // Delete all items first (this will trigger model events to delete files)
        $order->items()->each(function ($item) {
            $item->delete();
        });

        // Delete GIA file if exists
        if ($order->gia_path) {
            $order->deleteGia();
        }

        // Delete the order
        $order->delete();

        DB::commit();

        Log::info('Admin deleted order', [
            'order_number' => $orderNumber,
            'tracking_number' => $trackingNumber,
            'user_id' => $userId,
            'user_email' => $userEmail,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Order '{$orderNumber}' has been deleted successfully."
        ]);
    } catch (\Exception $e) {
        DB::rollBack();

        Log::error('Admin failed to delete order', [
            'order_id' => $order->id,
            'order_number' => $order->order_number ?? null,
            'admin_id' => $request->user()->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete order. Please try again.',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

/**
 * Bulk delete orders
 */
public function bulkDestroy(Request $request)
{
    $request->validate([
        'order_ids' => 'required|array|min:1|max:100',
        'order_ids.*' => 'required|integer|exists:orders,id',
    ], [
        'order_ids.required' => 'No orders selected for deletion.',
        'order_ids.array' => 'Invalid order selection format.',
        'order_ids.min' => 'At least one order must be selected.',
        'order_ids.max' => 'Cannot delete more than 100 orders at once.',
        'order_ids.*.exists' => 'One or more selected orders do not exist.',
    ]);

    DB::beginTransaction();

    try {
        $orderIds = $request->order_ids;
        $orders = Order::with(['user', 'items'])->whereIn('id', $orderIds)->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No orders found for deletion.'
            ], 404);
        }

        $deletedOrders = [];
        $failedOrders = [];

        foreach ($orders as $order) {
            try {
                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->tracking_number,
                    'user_id' => $order->user_id,
                    'user_email' => $order->user->email,
                    'status' => $order->status,
                ];

                // Delete all items first (this will trigger model events to delete files)
                $order->items()->each(function ($item) {
                    $item->delete();
                });

                // Delete GIA file if exists
                if ($order->gia_path) {
                    $order->deleteGia();
                }

                // Delete the order
                $order->delete();

                $deletedOrders[] = $orderData;

            } catch (\Exception $e) {
                $failedOrders[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to delete order in bulk operation', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::commit();

        Log::info('Bulk order deletion completed', [
            'admin_id' => $request->user()->id,
            'requested_count' => count($orderIds),
            'deleted_count' => count($deletedOrders),
            'failed_count' => count($failedOrders),
            'deleted_orders' => array_column($deletedOrders, 'order_number'),
            'failed_orders' => array_column($failedOrders, 'order_number'),
        ]);

        $message = count($deletedOrders) . ' order(s) deleted successfully.';
        if (count($failedOrders) > 0) {
            $message .= ' ' . count($failedOrders) . ' order(s) failed to delete.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'deleted' => $deletedOrders,
                'failed' => $failedOrders,
                'summary' => [
                    'total_requested' => count($orderIds),
                    'deleted_count' => count($deletedOrders),
                    'failed_count' => count($failedOrders),
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();

        Log::error('Bulk order deletion failed', [
            'admin_id' => $request->user()->id,
            'order_ids' => $orderIds ?? [],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Bulk deletion failed. No orders were deleted.',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

public function byStatus(Request $request, $status)
{
    $request->validate([
        'per_page' => 'nullable|integer|min:1|max:500',
        'limit' => 'nullable|integer|min:1|max:500',
    ]);

    $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

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

    $orders = $query->paginate($perPage);

    return response()->json([
        'success' => true,
        'data' => $orders
    ]);
}
}