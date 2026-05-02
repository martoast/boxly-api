<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreCheckoutController extends Controller
{
    /**
     * Boxly Store checkout — folds straight into the assisted Purchase Request flow.
     *
     * Creates a PR in `quoted` status with cart items prefilled (size/color in options,
     * source URL on each item so admin knows what to buy at the source store), then
     * opens a Stripe Checkout Session in MXN. The webhook flips the PR to `paid`.
     * Admin then proceeds with the existing "Mark as Purchased" flow → creates an
     * Order in awaiting_packages → standard shipping pipeline takes over.
     */
    public function create(Request $request)
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity'   => 'required|integer|min:1|max:100',
        ]);

        $user = $request->user();

        $productIds = collect($request->input('items'))->pluck('product_id')->all();
        $products = Product::with('variants')->whereIn('id', $productIds)->get()->keyBy('id');

        $variantsById = collect();
        $variantIds = collect($request->input('items'))->pluck('variant_id')->filter()->all();
        if (! empty($variantIds)) {
            $variantsById = ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
        }

        // Pre-validate availability + variant selection
        foreach ($request->input('items') as $line) {
            $product = $products->get($line['product_id']);
            if (! $product || ! $product->isAvailable()) {
                $name = $product->name ?? "Producto {$line['product_id']}";
                $reason = $product && $product->isOutOfStock()
                    ? "{$name} está agotado"
                    : "{$name} ya no está disponible";
                return response()->json(['success' => false, 'message' => $reason], 400);
            }

            if ($product->variants->isNotEmpty()) {
                $variant = $variantsById->get($line['variant_id'] ?? null);
                if (! $variant || $variant->product_id !== $product->id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Selecciona una talla/color para {$product->name}",
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
            $pr = DB::transaction(function () use ($user, $request, $products, $variantsById) {
                $itemsTotalCents = 0;
                $itemRows = [];

                foreach ($request->input('items') as $line) {
                    $product = $products->get($line['product_id']);
                    $variant = ! empty($line['variant_id']) ? $variantsById->get($line['variant_id']) : null;

                    $unitCents = $variant && $variant->price_cents
                        ? $variant->price_cents
                        : $product->price_cents;

                    $itemsTotalCents += $unitCents * $line['quantity'];

                    $options = array_filter([
                        'size'               => $variant?->size,
                        'color'              => $variant?->color,
                        'shopify_variant_id' => $variant?->shopify_variant_id,
                    ]);

                    $itemRows[] = [
                        'product_name'      => $product->name,
                        'product_url'       => $product->source_url,
                        'product_image_url' => $product->first_image_url,
                        'price'             => $unitCents / 100,
                        'quantity'          => $line['quantity'],
                        'options'           => $options ?: null,
                    ];
                }

                $itemsTotal = round($itemsTotalCents / 100, 2);

                $pr = PurchaseRequest::create([
                    'user_id'        => $user->id,
                    'request_number' => PurchaseRequest::generateRequestNumber(),
                    'status'         => PurchaseRequest::STATUS_QUOTED,
                    'source'         => PurchaseRequest::SOURCE_STORE,
                    'currency'       => 'mxn',
                    'payment_method' => PurchaseRequest::PAYMENT_METHOD_STRIPE,
                    'items_total'    => $itemsTotal,
                    'shipping_cost'  => 0,
                    'sales_tax'      => 0,
                    'processing_fee' => 0,
                    'total_amount'   => $itemsTotal,
                    'quote_sent_at'  => now(),
                    'admin_notes'    => 'Boxly Store checkout — markup already in product price.',
                ]);

                foreach ($itemRows as $row) {
                    PurchaseRequestItem::create(array_merge($row, [
                        'purchase_request_id' => $pr->id,
                    ]));
                }

                return $pr;
            });

            // Build Stripe line items from the saved PR items so receipt matches DB exactly
            $lineItems = $pr->items->map(function ($item) {
                $opts = $item->options ?? [];
                $suffix = trim(implode(' / ', array_filter([$opts['size'] ?? null, $opts['color'] ?? null])));
                $name = $suffix ? "{$item->product_name} ({$suffix})" : $item->product_name;

                return [
                    'price_data' => [
                        'currency'    => 'mxn',
                        'unit_amount' => (int) round($item->price * 100),
                        'product_data' => [
                            'name'   => $name,
                            'images' => $item->product_image_url ? [$item->product_image_url] : [],
                        ],
                    ],
                    'quantity' => $item->quantity,
                ];
            })->all();

            $checkout = $user->checkout($lineItems, [
                'success_url' => config('app.frontend_url') . '/app/purchase-requests/' . $pr->id . '?status=success',
                'cancel_url'  => config('app.frontend_url') . '/cart?status=cancelled',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'type'                => 'store_purchase',
                    'purchase_request_id' => $pr->id,
                    'user_id'             => $user->id,
                ],
                'payment_intent_data' => [
                    'description' => "Boxly Store — {$pr->request_number}",
                    'metadata' => [
                        'type'                => 'store_purchase',
                        'purchase_request_id' => $pr->id,
                        'user_id'             => $user->id,
                    ],
                ],
                'locale' => 'es-419',
            ]);

            $pr->update(['payment_link' => $checkout->url]);

            Log::info('Store checkout session created', [
                'session_id'          => $checkout->id,
                'purchase_request_id' => $pr->id,
                'user_id'             => $user->id,
                'total_mxn'           => $pr->total_amount,
            ]);

            return response()->json([
                'success'       => true,
                'checkout_url'  => $checkout->url,
                'session_id'    => $checkout->id,
                'request_number' => $pr->request_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Store checkout failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear la sesión de pago: ' . $e->getMessage(),
            ], 500);
        }
    }
}
