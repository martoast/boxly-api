<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketplaceCheckoutController extends Controller
{
    /**
     * Create a Stripe Checkout Session for a cart.
     *
     * Find-or-create the customer's open marketplace order (status=collecting),
     * snapshot product data into MarketplaceOrderItems (status=pending_payment),
     * tag them with the upcoming Stripe Checkout session id, then create the session.
     *
     * On webhook: items are flipped to status=ordered + paid_at set.
     */
    public function create(Request $request)
    {
        $request->validate([
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'required|integer|exists:products,id',
            'items.*.variant_id'          => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity'            => 'required|integer|min:1|max:100',
            'shipping_address'            => 'nullable|array',
            'shipping_address.full_address' => 'required_with:shipping_address|string|max:500',
        ]);

        $user = $request->user();

        // Pre-validate products are available + have stock
        $productIds = collect($request->input('items'))->pluck('product_id')->all();
        $products = Product::with('variants')->whereIn('id', $productIds)->get()->keyBy('id');

        $variantsById = collect();
        $variantIds = collect($request->input('items'))->pluck('variant_id')->filter()->all();
        if (! empty($variantIds)) {
            $variantsById = ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
        }

        foreach ($request->input('items') as $line) {
            $product = $products->get($line['product_id']);
            if (! $product) {
                return response()->json([
                    'success' => false,
                    'message' => "Product {$line['product_id']} not found",
                ], 404);
            }
            if (! $product->isAvailable()) {
                $reason = $product->isOutOfStock()
                    ? "{$product->name} está agotado"
                    : "{$product->name} ya no está disponible";
                return response()->json([
                    'success' => false,
                    'message' => $reason,
                ], 400);
            }
            if ($product->stock < $line['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => "Solo quedan {$product->stock} unidades de {$product->name}",
                ], 400);
            }

            // If product has variants, a variant_id is required and must belong to this product + be in stock
            $hasVariants = $product->variants->isNotEmpty();
            if ($hasVariants) {
                $variantId = $line['variant_id'] ?? null;
                if (! $variantId) {
                    return response()->json([
                        'success' => false,
                        'message' => "Selecciona una talla/color para {$product->name}",
                    ], 422);
                }
                $variant = $variantsById->get($variantId);
                if (! $variant || $variant->product_id !== $product->id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Variante inválida para {$product->name}",
                    ], 422);
                }
                if (! $variant->isAvailable()) {
                    return response()->json([
                        'success' => false,
                        'message' => "La variante {$variant->title} de {$product->name} está agotada",
                    ], 400);
                }
            }
        }

        try {
            $result = DB::transaction(function () use ($user, $request, $products) {
                // Find or create open order
                $order = MarketplaceOrder::where('user_id', $user->id)
                    ->where('status', MarketplaceOrder::STATUS_COLLECTING)
                    ->first();

                if (! $order) {
                    $order = MarketplaceOrder::create([
                        'user_id' => $user->id,
                        'status' => MarketplaceOrder::STATUS_COLLECTING,
                        'shipping_address' => $request->input('shipping_address'),
                    ]);
                } elseif ($request->input('shipping_address')) {
                    // Update shipping address if provided
                    $order->update(['shipping_address' => $request->input('shipping_address')]);
                }

                // Create pending items + decrement stock
                $createdItems = [];
                foreach ($request->input('items') as $line) {
                    $product = $products->get($line['product_id']);
                    $variant = ! empty($line['variant_id'])
                        ? $variantsById->get($line['variant_id'])
                        : null;

                    // Decrement stock now (reserve it)
                    $product->decrement('stock', $line['quantity']);

                    // Variant price override if set
                    $unitPrice = $variant && $variant->price_cents
                        ? $variant->price_cents
                        : $product->price_cents;

                    $item = MarketplaceOrderItem::create([
                        'marketplace_order_id' => $order->id,
                        'product_id'           => $product->id,
                        'variant_id'           => $variant?->id,
                        'name_snapshot'        => $product->name,
                        'price_cents_snapshot' => $unitPrice,
                        'weight_kg_snapshot'   => $product->weight_kg,
                        'image_url_snapshot'   => $product->first_image_url,
                        'size_snapshot'        => $variant?->size,
                        'color_snapshot'       => $variant?->color,
                        'quantity'             => $line['quantity'],
                        'status'               => MarketplaceOrderItem::STATUS_PENDING_PAYMENT,
                    ]);
                    $createdItems[] = $item;
                }

                return ['order' => $order, 'items' => $createdItems];
            });

            $order = $result['order'];
            $items = $result['items'];

            // Build Stripe line items
            $lineItems = [];
            foreach ($items as $item) {
                // Append size/color into name so customer's Stripe receipt is clear
                $variantSuffix = trim(implode(' / ', array_filter([$item->size_snapshot, $item->color_snapshot])));
                $stripeName = $variantSuffix
                    ? "{$item->name_snapshot} ({$variantSuffix})"
                    : $item->name_snapshot;

                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'mxn',
                        'unit_amount' => $item->price_cents_snapshot,
                        'product_data' => [
                            'name' => $stripeName,
                            'images' => $item->image_url_snapshot ? [$item->image_url_snapshot] : [],
                        ],
                    ],
                    'quantity' => $item->quantity,
                ];
            }

            $checkout = $user->checkout($lineItems, [
                'success_url' => config('app.frontend_url') . '/app/marketplace-orders/' . $order->id . '?status=success',
                'cancel_url'  => config('app.frontend_url') . '/cart?status=cancelled',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'type' => 'marketplace_purchase',
                    'marketplace_order_id' => $order->id,
                    'user_id' => $user->id,
                ],
                'payment_intent_data' => [
                    'description' => "Boxly Store — Order {$order->order_number}",
                    'metadata' => [
                        'type' => 'marketplace_purchase',
                        'marketplace_order_id' => $order->id,
                        'user_id' => $user->id,
                    ],
                ],
                'locale' => 'es-419',
            ]);

            // Tag the items with the session id so the webhook can find them
            $itemIds = collect($items)->pluck('id')->all();
            MarketplaceOrderItem::whereIn('id', $itemIds)->update([
                'stripe_checkout_session_id' => $checkout->id,
            ]);

            Log::info('Marketplace checkout session created', [
                'session_id' => $checkout->id,
                'marketplace_order_id' => $order->id,
                'user_id' => $user->id,
                'item_count' => count($items),
            ]);

            return response()->json([
                'success' => true,
                'checkout_url' => $checkout->url,
                'session_id'   => $checkout->id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Marketplace checkout failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear la sesión de pago: ' . $e->getMessage(),
            ], 500);
        }
    }
}
