<?php
namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Http\Requests\AdminShipOrderRequest;
use App\Models\Order;
use App\Models\OrderBox;
use App\Models\OrderItem;
use App\Models\User;
use App\Mail\OrderShippedWithDeposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

        $query = Order::with(['user', 'items', 'boxes']);

        if ($request->has('status')) {
            $query->status($request->status);
        }

        if ($request->has('items_expected_by')) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->expectedBy($request->items_expected_by);
            });
        }

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

        $total = $query->count();
        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders,
            'total' => $total,
        ]);
    }

    public function show(Order $order)
    {
        return response()->json([
            'success' => true,
            'data' => $order->load(['user', 'items', 'boxes'])
        ]);
    }

    public function readyToShip(Request $request)
    {
        $perPage = $request->input('per_page') ?? 20;
        $query = Order::with(['user', 'items', 'boxes'])
            ->status(Order::STATUS_PROCESSING)
            ->oldest('processing_started_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
            'total' => $query->count(),
        ]);
    }

    public function readyToProcess(Request $request)
    {
        $perPage = $request->input('per_page') ?? 20;
        $query = Order::with(['user', 'items', 'boxes'])
            ->status(Order::STATUS_PACKAGES_COMPLETE)
            ->oldest('updated_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
            'total' => $query->count(),
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
                // Only set estimated_delivery_date if provided (required for shipping orders, optional for crossing)
                if ($request->has('estimated_delivery_date')) {
                    $data['estimated_delivery_date'] = $request->estimated_delivery_date;
                }
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

        Log::info('Admin manually updated order status', [
            'order_id' => $order->id,
            'new_status' => $request->status,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh()->load(['user', 'items', 'boxes'])
        ]);
    }

    /**
     * Ship order: Supports multiple boxes per order.
     * Creates OrderBox entries, calculates 50% deposit of total box price, creates invoice.
     *
     * Request can contain:
     * - boxes: array of [{stripe_price_id, quantity}] for multiple boxes
     * - stripe_price_id: single price ID (backwards compatible, converted to boxes array)
     */
    public function shipOrder(AdminShipOrderRequest $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PROCESSING) {
            return response()->json(['success' => false, 'message' => 'Only orders in processing can be shipped'], 400);
        }

        DB::beginTransaction();

        try {
            $user = $order->user;
            $stripe = Cashier::stripe();

            // 1. Fetch all box details from Stripe and calculate totals
            $boxEntries = [];
            $totalBoxPrice = 0;
            $currency = 'mxn';
            $boxDescriptions = [];

            foreach ($request->boxes as $boxInput) {
                try {
                    $stripePrice = $stripe->prices->retrieve($boxInput['stripe_price_id'], [
                        'expand' => ['product']
                    ]);
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid Stripe Price ID: {$boxInput['stripe_price_id']}"
                    ], 422);
                }

                $quantity = $boxInput['quantity'] ?? 1;
                $boxPrice = $stripePrice->unit_amount / 100;
                $boxName = $stripePrice->product->name;
                $boxSize = $stripePrice->product->metadata->type ?? null;
                $currency = strtolower($stripePrice->currency);

                $boxEntries[] = [
                    'stripe_price_id' => $stripePrice->id,
                    'stripe_product_id' => $stripePrice->product->id,
                    'box_size' => $boxSize,
                    'box_name' => $boxName,
                    'box_price' => $boxPrice,
                    'currency' => $currency,
                    'quantity' => $quantity,
                ];

                $lineTotal = $boxPrice * $quantity;
                $totalBoxPrice += $lineTotal;

                // Build description for invoice line item
                $desc = $quantity > 1 ? "{$quantity}x {$boxName}" : $boxName;
                $boxDescriptions[] = $desc;
            }

            // 2. Calculate payment amount (100% for crossing, 50% deposit for shipping)
            $isCrossing = $order->isCrossingOnly();
            $paymentPercentage = $isCrossing ? 1.0 : 0.5;
            $depositAmount = round($totalBoxPrice * $paymentPercentage, 2);

            // 3. Handle GIA File Upload
            $uploadedPath = null;
            if ($request->hasFile('gia_file')) {
                $file = $request->file('gia_file');
                $userName = Str::slug($user->name);
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/shipping";
                $filename = "gia-" . time() . ".pdf";

                $uploadedPath = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url = config('filesystems.disks.spaces.url') . '/' . $uploadedPath;

                $order->gia_path = $uploadedPath;
                $order->gia_filename = $file->getClientOriginalName();
                $order->gia_mime_type = $file->getClientMimeType();
                $order->gia_size = $file->getSize();
                $order->gia_url = $url;
            }

            // 4. Create Stripe Customer if needed
            if (!$user->stripe_id) {
                $user->createAsStripeCustomer();
            }

            // 5. Create Stripe Invoice (Full Payment for crossing, 50% Deposit for shipping)
            $boxCount = count($boxEntries);
            $paymentLabel = $isCrossing ? "Full Payment" : "Deposit (50%)";
            $invoiceDescription = $boxCount > 1
                ? "{$paymentLabel} for Order {$order->order_number} - {$boxCount} boxes"
                : "{$paymentLabel} for Order {$order->order_number}";

            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'currency' => $currency,
                'collection_method' => 'send_invoice',
                'days_until_due' => 3,
                'description' => $invoiceDescription,
                'metadata' => [
                    'type' => $isCrossing ? 'full_payment' : 'deposit',
                    'order_type' => $order->order_type ?? 'shipping',
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'box_count' => (string) $boxCount,
                    'total_box_price' => (string) $totalBoxPrice,
                    'payment_percentage' => $isCrossing ? '100' : '50',
                ],
                'auto_advance' => false,
            ]);

            // Create line items for each box type
            foreach ($boxEntries as $entry) {
                $linePrefix = $isCrossing ? "Full Payment" : "50% Deposit";
                $lineDescription = $entry['quantity'] > 1
                    ? "{$linePrefix}: {$entry['quantity']}x {$entry['box_name']} @ \${$entry['box_price']} each"
                    : "{$linePrefix}: {$entry['box_name']} (\${$entry['box_price']})";

                $lineAmount = round(($entry['box_price'] * $entry['quantity']) * $paymentPercentage, 2);

                $stripe->invoiceItems->create([
                    'customer' => $user->stripe_id,
                    'invoice' => $stripeInvoice->id,
                    'amount' => intval($lineAmount * 100),
                    'currency' => $currency,
                    'description' => $lineDescription,
                ]);
            }

            $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            // 6. Clear any existing boxes and create new OrderBox entries
            $order->boxes()->delete();
            foreach ($boxEntries as $entry) {
                OrderBox::create([
                    'order_id' => $order->id,
                    'stripe_price_id' => $entry['stripe_price_id'],
                    'stripe_product_id' => $entry['stripe_product_id'],
                    'box_size' => $entry['box_size'],
                    'box_name' => $entry['box_name'],
                    'box_price' => $entry['box_price'],
                    'currency' => $entry['currency'],
                    'quantity' => $entry['quantity'],
                ]);
            }

            // 7. Update Order Data
            // For backwards compatibility, also set the legacy single-box fields
            // using the first/primary box if there's only one
            $primaryBox = $boxEntries[0];

            $order->status = Order::STATUS_SHIPPED;
            $order->guia_number = $request->guia_number;
            $order->estimated_delivery_date = $request->estimated_delivery_date;
            $order->shipped_at = now();

            // Legacy single-box fields (for backwards compatibility)
            $order->box_size = count($boxEntries) === 1 ? $primaryBox['box_size'] : null;
            $order->box_price = $totalBoxPrice; // Store total for easy access
            $order->stripe_price_id = count($boxEntries) === 1 ? $primaryBox['stripe_price_id'] : null;
            $order->stripe_product_id = count($boxEntries) === 1 ? $primaryBox['stripe_product_id'] : null;
            $order->currency = $currency;

            // Deposit Info
            $order->deposit_amount = $depositAmount;
            $order->deposit_invoice_id = $stripeInvoice->id;
            $order->deposit_payment_link = $sentInvoice->hosted_invoice_url;

            if ($request->has('notes')) {
                $order->notes = ($order->notes ? $order->notes . "\n" : '') . "Shipped: " . $request->notes;
            }

            $order->skipEmailNotifications = true;
            $order->save();

            DB::commit();

            // 8. Send Email
            try {
                Mail::to($user)->queue(new OrderShippedWithDeposit($order));
                Log::info('Order shipped email queued', [
                    'order_id' => $order->id,
                    'order_type' => $order->order_type ?? 'shipping',
                    'box_count' => $boxCount,
                    'total_box_price' => $totalBoxPrice,
                    'payment_amount' => $depositAmount,
                    'payment_type' => $isCrossing ? 'full_payment' : 'deposit',
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to queue email', ['error' => $e->getMessage()]);
            }

            $invoiceType = $isCrossing ? 'full payment' : 'deposit';
            return response()->json([
                'success' => true,
                'message' => $boxCount > 1
                    ? "Order shipped with {$boxCount} boxes. " . ucfirst($invoiceType) . " invoice generated."
                    : "Order shipped and {$invoiceType} invoice generated successfully",
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items', 'boxes']),
                    'payment_link' => $order->deposit_payment_link,
                    'deposit_link' => $order->deposit_payment_link, // Legacy compatibility
                    'boxes' => $boxEntries,
                    'total_box_price' => $totalBoxPrice,
                    'payment_amount' => $depositAmount,
                    'payment_type' => $isCrossing ? 'full_payment' : 'deposit',
                    'payment_percentage' => $isCrossing ? 100 : 50,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($uploadedPath)) Storage::disk('spaces')->delete($uploadedPath);
            Log::error('Failed to ship order', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function viewGia(Request $request, Order $order)
    {
        if (!$order->gia_path) {
            return response()->json(['success' => false, 'message' => 'No GIA document'], 404);
        }
        if ($order->gia_url) {
            return redirect($order->gia_full_url);
        }
        return response()->json(['success' => false, 'message' => 'URL unavailable'], 404);
    }

    public function destroy(Request $request, Order $order)
    {
        DB::beginTransaction();
        try {
            $order->items()->each(fn($i) => $i->delete());
            if ($order->gia_path) $order->deleteGia();
            $order->delete();
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Order deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate(['order_ids' => 'required|array|min:1']);
        DB::beginTransaction();
        try {
            $orders = Order::whereIn('id', $request->order_ids)->get();
            foreach ($orders as $order) {
                $order->items()->each(fn($i) => $i->delete());
                if ($order->gia_path) $order->deleteGia();
                $order->delete();
            }
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Orders deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}