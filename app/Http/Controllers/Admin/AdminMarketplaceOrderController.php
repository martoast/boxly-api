<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MarketplaceShippedMail;
use App\Mail\MarketplaceShippingInvoiceMail;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Cashier;

class AdminMarketplaceOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:200',
            'status'   => 'nullable|string',
            'search'   => 'nullable|string|max:200',
        ]);

        $perPage = (int) $request->input('per_page', 20);

        $query = MarketplaceOrder::with(['user', 'items']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function show(MarketplaceOrder $marketplaceOrder)
    {
        $marketplaceOrder->load(['user', 'items.product']);

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder,
        ]);
    }

    public function markItemReceived(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderItem $item)
    {
        if ($item->marketplace_order_id !== $marketplaceOrder->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this order'], 400);
        }

        $item->update([
            'status' => MarketplaceOrderItem::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        // If all items are received and order is ready_to_ship, advance to packing
        $allReceived = ! $marketplaceOrder->items()
            ->whereIn('status', [
                MarketplaceOrderItem::STATUS_PENDING_PAYMENT,
                MarketplaceOrderItem::STATUS_ORDERED,
            ])
            ->exists();

        if ($allReceived && $marketplaceOrder->status === MarketplaceOrder::STATUS_READY_TO_SHIP) {
            $marketplaceOrder->update(['status' => MarketplaceOrder::STATUS_PACKING]);
        }

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder->fresh(['items.product']),
        ]);
    }

    /**
     * Items waiting to be purchased from their source store (US retailer).
     * After customer pays Boxly, admin opens this list to go buy from each retailer.
     */
    public function pendingSourcePurchase(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $perPage = (int) $request->input('per_page', 30);

        $items = MarketplaceOrderItem::with(['order.user', 'product:id,name,source_url,images'])
            ->whereNotNull('paid_at')
            ->whereNull('source_purchased_at')
            ->whereIn('status', [MarketplaceOrderItem::STATUS_ORDERED, MarketplaceOrderItem::STATUS_PENDING_PAYMENT])
            ->orderBy('paid_at', 'asc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Admin records the purchase they made at the US source store.
     */
    public function recordSourcePurchase(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderItem $item)
    {
        if ($item->marketplace_order_id !== $marketplaceOrder->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this order'], 400);
        }

        $validated = $request->validate([
            'source_order_id'        => 'nullable|string|max:100',
            'source_tracking_number' => 'nullable|string|max:100',
            'source_carrier'         => 'nullable|string|max:50',
        ]);

        $item->update(array_merge($validated, [
            'source_purchased_at' => now(),
        ]));

        return response()->json([
            'success' => true,
            'data' => $item->fresh(['order.user', 'product']),
        ]);
    }

    public function unmarkItemReceived(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderItem $item)
    {
        if ($item->marketplace_order_id !== $marketplaceOrder->id) {
            return response()->json(['success' => false, 'message' => 'Item not in this order'], 400);
        }

        $item->update([
            'status' => MarketplaceOrderItem::STATUS_ORDERED,
            'received_at' => null,
        ]);

        // If order was packing, drop back to ready_to_ship
        if ($marketplaceOrder->status === MarketplaceOrder::STATUS_PACKING) {
            $marketplaceOrder->update(['status' => MarketplaceOrder::STATUS_READY_TO_SHIP]);
        }

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder->fresh(['items.product']),
        ]);
    }

    /**
     * Admin assigns a box size + price, generates Stripe shipping invoice, emails customer.
     */
    public function assignBox(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $request->validate([
            'box_size'    => 'required|in:XS,S,M,L,XL',
            'box_price_cents' => 'nullable|integer|min:0',
            'box_summary' => 'nullable|array',
            'estimated_delivery_date' => 'nullable|date',
        ]);

        if (! in_array($marketplaceOrder->status, [
            MarketplaceOrder::STATUS_READY_TO_SHIP,
            MarketplaceOrder::STATUS_PACKING,
            MarketplaceOrder::STATUS_AWAITING_SHIPPING_PAYMENT,
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'Esta orden no está en un estado para asignar caja.',
            ], 400);
        }

        $boxSize = $request->input('box_size');
        $boxPriceCents = $request->input('box_price_cents')
            ?? (Product::BOX_THRESHOLDS[$boxSize]['price_cents'] ?? 0);

        try {
            DB::transaction(function () use ($marketplaceOrder, $boxSize, $boxPriceCents, $request) {
                $marketplaceOrder->update([
                    'box_size' => $boxSize,
                    'box_price_cents' => $boxPriceCents,
                    'box_summary' => $request->input('box_summary'),
                    'estimated_delivery_date' => $request->input('estimated_delivery_date'),
                ]);
            });

            $user = $marketplaceOrder->user;

            // Create Stripe invoice for shipping payment
            $stripe = Cashier::stripe();
            $stripeCustomerId = $user->stripe_id;
            if (! $stripeCustomerId) {
                $user->createAsStripeCustomer();
                $stripeCustomerId = $user->stripe_id;
            }

            // Add invoice item
            $stripe->invoiceItems->create([
                'customer' => $stripeCustomerId,
                'amount' => $boxPriceCents,
                'currency' => 'mxn',
                'description' => "Boxly Store — Envío caja {$boxSize} (orden {$marketplaceOrder->order_number})",
            ]);

            // Create the invoice
            $invoice = $stripe->invoices->create([
                'customer' => $stripeCustomerId,
                'collection_method' => 'send_invoice',
                'days_until_due' => 14,
                'metadata' => [
                    'type' => 'marketplace_shipping',
                    'marketplace_order_id' => $marketplaceOrder->id,
                    'user_id' => $user->id,
                ],
                'description' => "Envío para orden {$marketplaceOrder->order_number} (caja {$boxSize})",
            ]);

            $finalized = $stripe->invoices->finalizeInvoice($invoice->id);
            $sent = $stripe->invoices->sendInvoice($finalized->id);

            $marketplaceOrder->update([
                'status' => MarketplaceOrder::STATUS_AWAITING_SHIPPING_PAYMENT,
                'shipping_invoice_id' => $sent->id,
                'shipping_payment_link' => $sent->hosted_invoice_url,
            ]);

            try {
                Mail::to($user)->queue(new MarketplaceShippingInvoiceMail($marketplaceOrder->fresh()));
            } catch (\Exception $e) {
                Log::error('Failed to queue shipping invoice email', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'data' => $marketplaceOrder->fresh(['items.product', 'user']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to assign box', [
                'order_id' => $marketplaceOrder->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo asignar la caja: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload GIA waybill (admin uses same Spaces pattern as forwarding orders).
     */
    public function uploadGia(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $request->validate([
            'gia' => 'required|file|mimes:pdf,jpeg,jpg,png|max:10240',
            'guia_number' => 'nullable|string|max:100',
        ]);

        try {
            $file = $request->file('gia');
            $filename = 'gia-' . $marketplaceOrder->order_number . '-' . time() . '.' . $file->getClientOriginalExtension();
            $path = Storage::disk('spaces')->putFileAs(
                "marketplace/{$marketplaceOrder->order_number}",
                $file,
                $filename,
                'public'
            );
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $marketplaceOrder->update([
                'gia_path' => $path,
                'gia_url' => $url,
                'guia_number' => $request->input('guia_number') ?? $marketplaceOrder->guia_number,
            ]);

            return response()->json([
                'success' => true,
                'data' => $marketplaceOrder->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('GIA upload failed', ['order_id' => $marketplaceOrder->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Upload failed'], 500);
        }
    }

    public function markShipped(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $request->validate([
            'guia_number' => 'nullable|string|max:100',
            'estimated_delivery_date' => 'nullable|date',
        ]);

        if ($marketplaceOrder->status !== MarketplaceOrder::STATUS_SHIPPING_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'La orden debe tener el envío pagado antes de marcarse como enviada.',
            ], 400);
        }

        $marketplaceOrder->update([
            'status' => MarketplaceOrder::STATUS_SHIPPED,
            'shipped_at' => now(),
            'guia_number' => $request->input('guia_number') ?? $marketplaceOrder->guia_number,
            'estimated_delivery_date' => $request->input('estimated_delivery_date') ?? $marketplaceOrder->estimated_delivery_date,
        ]);

        // Mark all items as shipped
        $marketplaceOrder->items()->update(['status' => MarketplaceOrderItem::STATUS_SHIPPED]);

        try {
            Mail::to($marketplaceOrder->user)->queue(new MarketplaceShippedMail($marketplaceOrder->fresh()));
        } catch (\Exception $e) {
            Log::error('Failed to queue shipped email', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder->fresh(['items.product', 'user']),
        ]);
    }

    public function markDelivered(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        if ($marketplaceOrder->status !== MarketplaceOrder::STATUS_SHIPPED) {
            return response()->json([
                'success' => false,
                'message' => 'La orden debe estar enviada antes de marcarse como entregada.',
            ], 400);
        }

        $marketplaceOrder->update([
            'status' => MarketplaceOrder::STATUS_DELIVERED,
            'actual_delivery_date' => now()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder->fresh(),
        ]);
    }

    public function refund(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $request->validate([
            'amount_cents' => 'nullable|integer|min:0',
            'reason' => 'required|string|max:500',
        ]);

        if (in_array($marketplaceOrder->status, [
            MarketplaceOrder::STATUS_SHIPPED,
            MarketplaceOrder::STATUS_DELIVERED,
            MarketplaceOrder::STATUS_REFUNDED,
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede reembolsar una orden ya enviada/entregada/reembolsada.',
            ], 400);
        }

        $amountCents = $request->input('amount_cents') ?? $marketplaceOrder->items_subtotal_cents;

        try {
            DB::transaction(function () use ($marketplaceOrder, $amountCents, $request) {
                // Restore stock for items that haven't been received yet
                foreach ($marketplaceOrder->items as $item) {
                    if ($item->status !== MarketplaceOrderItem::STATUS_RECEIVED) {
                        Product::where('id', $item->product_id)->increment('stock', $item->quantity);
                    }
                }

                $marketplaceOrder->update([
                    'status' => MarketplaceOrder::STATUS_REFUNDED,
                    'refunded_at' => now(),
                    'refund_amount_cents' => $amountCents,
                    'refund_reason' => $request->input('reason'),
                ]);
            });

            // Stripe refund — pick first paid payment intent
            $paymentIntents = $marketplaceOrder->items()
                ->whereNotNull('stripe_payment_intent_id')
                ->pluck('stripe_payment_intent_id')
                ->unique();

            $stripe = Cashier::stripe();
            $remaining = $amountCents;
            foreach ($paymentIntents as $pi) {
                if ($remaining <= 0) break;
                try {
                    $refund = $stripe->refunds->create([
                        'payment_intent' => $pi,
                        'amount' => $remaining,
                        'reason' => 'requested_by_customer',
                        'metadata' => [
                            'marketplace_order_id' => $marketplaceOrder->id,
                            'reason' => $request->input('reason'),
                        ],
                    ]);
                    $remaining -= $refund->amount;
                } catch (\Exception $e) {
                    Log::warning('Partial refund failed', [
                        'payment_intent' => $pi,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $marketplaceOrder->fresh(['items.product']),
            ]);
        } catch (\Exception $e) {
            Log::error('Refund failed', [
                'order_id' => $marketplaceOrder->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo procesar el reembolso: ' . $e->getMessage(),
            ], 500);
        }
    }
}
