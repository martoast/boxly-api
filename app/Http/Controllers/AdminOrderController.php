<?php
namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Http\Requests\AdminShipOrderRequest;
use App\Models\Order;
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

        $query = Order::with(['user', 'items']);

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
            'data' => $order->load(['user', 'items'])
        ]);
    }

    public function readyToShip(Request $request)
    {
        $perPage = $request->input('per_page') ?? 20;
        $query = Order::with(['user', 'items'])
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
        $query = Order::with(['user', 'items'])
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

        $order->skipEmailNotifications = true;
        $order->update($data);

        Log::info('Admin manually updated order status', [
            'order_id' => $order->id,
            'new_status' => $request->status,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh()->load(['user', 'items'])
        ]);
    }

    /**
     * Ship order: Uses Stripe Price ID to set box details, calculates 50% deposit, creates invoice
     */
    public function shipOrder(AdminShipOrderRequest $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PROCESSING) {
            return response()->json(['success' => false, 'message' => 'Only orders in processing can be shipped'], 400);
        }

        DB::beginTransaction();

        try {
            $user = $order->user;

            // 1. Fetch Price/Product Data from Stripe
            try {
                $stripePrice = Cashier::stripe()->prices->retrieve($request->stripe_price_id, [
                    'expand' => ['product']
                ]);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Invalid Stripe Price ID provided'], 422);
            }

            // Extract details
            $boxPrice = $stripePrice->unit_amount / 100; // Convert cents to main unit
            $boxName = $stripePrice->product->name; // e.g. "Medium Box"
            $boxSize = $stripePrice->product->metadata->type ?? null; // e.g. "medium"

            // 2. Calculate 50% Deposit
            $depositAmount = round($boxPrice * 0.5, 2);

            // 3. Handle GIA File Upload
            if ($request->hasFile('gia_file')) {
                $file = $request->file('gia_file');
                $userName = Str::slug($user->name);
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/shipping";
                $filename = "gia-" . time() . ".pdf";
                
                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url = config('filesystems.disks.spaces.url') . '/' . $path;
                
                $order->gia_path = $path;
                $order->gia_filename = $file->getClientOriginalName();
                $order->gia_mime_type = $file->getClientMimeType();
                $order->gia_size = $file->getSize();
                $order->gia_url = $url;
            }

            // 4. Create Stripe Invoice for Deposit
            if (!$user->stripe_id) {
                $user->createAsStripeCustomer();
            }
            $stripe = Cashier::stripe();

            $stripeInvoice = $stripe->invoices->create([
                'customer' => $user->stripe_id,
                'currency' => strtolower($stripePrice->currency),
                'collection_method' => 'send_invoice',
                'days_until_due' => 3,
                'description' => "Deposit (50%) for Order {$order->order_number}",
                'metadata' => [
                    'type' => 'deposit',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'stripe_price_id' => $stripePrice->id,
                ],
                'auto_advance' => false,
            ]);

            $stripe->invoiceItems->create([
                'customer' => $user->stripe_id,
                'invoice' => $stripeInvoice->id,
                'amount' => intval($depositAmount * 100),
                'currency' => strtolower($stripePrice->currency),
                'description' => "50% Deposit for {$boxName} (Total: \${$boxPrice} {$stripePrice->currency})",
            ]);

            $stripe->invoices->finalizeInvoice($stripeInvoice->id);
            $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

            // 5. Update Order Data
            $order->status = Order::STATUS_SHIPPED;
            $order->guia_number = $request->guia_number;
            $order->estimated_delivery_date = $request->estimated_delivery_date;
            $order->shipped_at = now();
            
            // Set Box/Product Info
            $order->box_size = $boxSize;
            $order->box_price = $boxPrice;
            $order->stripe_price_id = $stripePrice->id;
            $order->stripe_product_id = $stripePrice->product->id;
            $order->currency = strtolower($stripePrice->currency);
            
            // Set Deposit Info
            $order->deposit_amount = $depositAmount;
            $order->deposit_invoice_id = $stripeInvoice->id;
            $order->deposit_payment_link = $sentInvoice->hosted_invoice_url;

            if ($request->has('notes')) {
                $order->notes = ($order->notes ? $order->notes . "\n" : '') . "Shipped: " . $request->notes;
            }

            $order->skipEmailNotifications = true; // We send custom email
            $order->save();

            DB::commit();

            // 6. Send Email
            try {
                Mail::to($user)->queue(new OrderShippedWithDeposit($order));
                Log::info('Order shipped email queued', ['order_id' => $order->id]);
            } catch (\Exception $e) {
                Log::error('Failed to queue email', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order shipped and deposit invoice generated successfully',
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items']),
                    'deposit_link' => $order->deposit_payment_link
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($path)) Storage::disk('spaces')->delete($path);
            Log::error('Failed to ship order', ['error' => $e->getMessage()]);
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