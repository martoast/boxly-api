<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketplaceOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $orders = MarketplaceOrder::forUser($request->user()->id)
            ->with(['items'])
            ->latest()
            ->paginate((int) $request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function show(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $this->authorizeOwnership($request, $marketplaceOrder);

        $marketplaceOrder->load(['items.product']);

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder,
        ]);
    }

    /**
     * Return the user's currently-open (collecting) marketplace order, or null.
     * Used by the storefront to show "in your shipment" context.
     */
    public function current(Request $request)
    {
        $order = MarketplaceOrder::forUser($request->user()->id)
            ->collecting()
            ->with(['items'])
            ->first();

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Customer requests their open order be shipped.
     * Moves status from 'collecting' to 'ready_to_ship'.
     */
    public function requestShipment(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $this->authorizeOwnership($request, $marketplaceOrder);

        if (! $marketplaceOrder->canRequestShipment()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta orden no puede solicitar envío en este momento.',
            ], 400);
        }

        $request->validate([
            'shipping_address' => 'nullable|array',
            'shipping_address.full_address' => 'required_with:shipping_address|string|max:500',
        ]);

        $update = [
            'status' => MarketplaceOrder::STATUS_READY_TO_SHIP,
            'shipment_requested_at' => now(),
        ];
        if ($request->input('shipping_address')) {
            $update['shipping_address'] = $request->input('shipping_address');
        }

        $marketplaceOrder->update($update);

        Log::info('Marketplace shipment requested', [
            'marketplace_order_id' => $marketplaceOrder->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $marketplaceOrder->fresh(['items.product']),
        ]);
    }

    /**
     * Customer cancels an order while still in collecting or ready_to_ship.
     * Restores stock for any items not yet received.
     */
    public function cancel(Request $request, MarketplaceOrder $marketplaceOrder)
    {
        $this->authorizeOwnership($request, $marketplaceOrder);

        if (! $marketplaceOrder->isCancellable()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta orden ya no puede cancelarse.',
            ], 400);
        }

        try {
            DB::transaction(function () use ($marketplaceOrder) {
                // Restore stock for items not yet received
                foreach ($marketplaceOrder->items as $item) {
                    if ($item->status !== MarketplaceOrderItem::STATUS_RECEIVED) {
                        Product::where('id', $item->product_id)->increment('stock', $item->quantity);
                    }
                }
                $marketplaceOrder->update([
                    'status' => MarketplaceOrder::STATUS_CANCELLED,
                ]);
            });

            return response()->json([
                'success' => true,
                'data' => $marketplaceOrder->fresh(['items.product']),
            ]);
        } catch (\Exception $e) {
            Log::error('Marketplace order cancel failed', [
                'order_id' => $marketplaceOrder->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo cancelar la orden.',
            ], 500);
        }
    }

    private function authorizeOwnership(Request $request, MarketplaceOrder $order): void
    {
        if ($order->user_id !== $request->user()->id) {
            abort(403, 'No autorizado.');
        }
    }
}
