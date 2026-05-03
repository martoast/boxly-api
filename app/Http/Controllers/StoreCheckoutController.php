<?php

namespace App\Http\Controllers;

use App\Mail\PurchaseRequestCreated;
use App\Mail\PurchaseRequestCreatedTeamNotification;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StoreCheckoutController extends Controller
{
    /**
     * Boxly Store checkout — creates a Purchase Request in pending_review.
     *
     * NOT a Stripe checkout anymore. The customer doesn't pay at this step.
     * Velonie reviews the PR, marks each item available/unavailable (some
     * source retailers run out between when we listed the product and when
     * the customer ordered), then generates a Stripe invoice for ONLY the
     * available items via the existing AdminPurchaseRequestController::createQuote
     * flow. Customer pays the invoice → webhook flips status → Velonie
     * "Mark as Purchased" creates the Order. Same final pipeline as assisted
     * PRs from this point on.
     *
     * Pricing note: store products have markup baked into their listing
     * price already, so processing_fee stays 0 for store PRs (createQuote
     * branches on source).
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

        // Pre-validate that the cart references real, listable products.
        // Stock-availability at the SOURCE retailer is now Velonie's review
        // step (not auto-blocked here) — so we don't reject on out_of_stock.
        foreach ($request->input('items') as $line) {
            $product = $products->get($line['product_id']);
            if (! $product) {
                return response()->json([
                    'success' => false,
                    'message' => "Producto {$line['product_id']} no existe",
                ], 422);
            }
            if ($product->variants->isNotEmpty()) {
                $variant = $variantsById->get($line['variant_id'] ?? null);
                if (! $variant || $variant->product_id !== $product->id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Selecciona una talla/color para {$product->name}",
                    ], 422);
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
                        'stock_status'      => PurchaseRequestItem::STOCK_UNVERIFIED,
                    ];
                }

                $itemsTotal = round($itemsTotalCents / 100, 2);

                $pr = PurchaseRequest::create([
                    'user_id'        => $user->id,
                    'request_number' => PurchaseRequest::generateRequestNumber(),
                    'status'         => PurchaseRequest::STATUS_PENDING_REVIEW,
                    'source'         => PurchaseRequest::SOURCE_STORE,
                    'currency'       => 'mxn',
                    'payment_method' => PurchaseRequest::PAYMENT_METHOD_STRIPE,
                    'items_total'    => $itemsTotal,
                    'shipping_cost'  => 0,
                    'sales_tax'      => 0,
                    'processing_fee' => 0, // markup is baked into product price
                    'total_amount'   => $itemsTotal,
                    'admin_notes'    => 'Boxly Store checkout — markup already in product price.',
                ]);

                foreach ($itemRows as $row) {
                    PurchaseRequestItem::create(array_merge($row, [
                        'purchase_request_id' => $pr->id,
                    ]));
                }

                return $pr;
            });

            // Notify the customer that we got their request
            try {
                Mail::to($user)->queue(new PurchaseRequestCreated($pr));
            } catch (\Exception $e) {
                Log::warning('Failed to queue store PR customer email', ['error' => $e->getMessage()]);
            }

            // Notify the shopping team so Velonie can verify stock
            try {
                $teamRecipients = User::where('role', 'employee')
                    ->where('team', 'shopping')
                    ->whereNotNull('email')
                    ->get();
                foreach ($teamRecipients as $recipient) {
                    Mail::to($recipient)->queue(new PurchaseRequestCreatedTeamNotification($pr));
                }
            } catch (\Exception $e) {
                Log::warning('Failed to queue store PR team email', ['error' => $e->getMessage()]);
            }

            Log::info('Store PR created (pending review)', [
                'purchase_request_id' => $pr->id,
                'request_number'      => $pr->request_number,
                'user_id'             => $user->id,
                'item_count'          => count($pr->items),
                'items_total_mxn'     => $pr->total_amount,
            ]);

            return response()->json([
                'success'             => true,
                'purchase_request_id' => $pr->id,
                'request_number'      => $pr->request_number,
                'redirect_url'        => '/app/purchase-requests/' . $pr->id,
                'message'             => 'Solicitud creada — Velonie verificará disponibilidad pronto.',
            ]);
        } catch (\Exception $e) {
            Log::error('Store checkout failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear la solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }
}
