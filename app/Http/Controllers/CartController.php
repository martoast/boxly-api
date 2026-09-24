<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Services\CartSync;
use App\Services\PurchaseRequestIntake;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The Boxly cart — one persisted, multi-store list per customer that becomes ONE
 * purchase request on finalize. Chat, live shopping and the extension all add to
 * the same cart.
 *
 * Only the OPEN cart is ever addressable: items of a finalized cart are history
 * and 404 like anyone else's.
 */
class CartController extends Controller
{
    private const STORE_ID_REGEX = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    /** Our variant keys → the Spanish option labels the rest of the PR pipeline uses. */
    private const OPTION_LABELS = [
        'size' => 'Talla', 'talla' => 'Talla',
        'color' => 'Color', 'colour' => 'Color',
    ];

    public function __construct(private PurchaseRequestIntake $intake)
    {
    }

    public function show(Request $request): JsonResponse
    {
        // Code can land before its migration during a deploy; "no cart yet" is
        // the honest answer until it does.
        $cart = Schema::hasTable('carts') ? $this->openCart($request->user()->id) : null;

        return response()->json(['data' => $this->cartPayload($cart)]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'string', 'regex:' . self::STORE_ID_REGEX],
            'store_name' => 'nullable|string|max:255',
            'product_url' => 'required|string|max:16000|url:https',
            'title' => 'required|string|max:500',
            'image_url' => 'nullable|string|max:16000|url:https',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
            'quantity' => 'sometimes|integer|min:1|max:' . CartItem::MAX_QUANTITY,
            'variants' => ['sometimes', 'array', $this->variantsRule()],
            'source' => 'required|in:' . implode(',', CartItem::SOURCES),
            'saved_id' => 'nullable|string|max:255',
            'conversation_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $variants = $this->normalizeVariants($data['variants'] ?? []);
        $quantity = (int) ($data['quantity'] ?? 1);
        $url = trim($data['product_url']);

        // Only link a chat this customer actually owns; anything else is ignored.
        $conversationId = $data['conversation_id'] ?? null;
        if ($conversationId && ! Conversation::where('id', $conversationId)->where('user_id', $user->id)->exists()) {
            $conversationId = null;
        }

        [$cart, $item, $sync] = DB::transaction(function () use ($user, $data, $variants, $quantity, $url, $conversationId) {
            $cart = $this->openCartForWrite($user->id);

            if ($conversationId && ! $cart->conversation_id) {
                $cart->update(['conversation_id' => $conversationId]);
            }

            $hash = CartItem::urlHash($url);
            $key = CartItem::variantsKey($variants);

            $item = $cart->items()
                ->where('product_url_hash', $hash)
                ->where('variants_key', $key)
                ->lockForUpdate()
                ->first();

            if ($item) {
                // Same product, same selection: one line, more units.
                $newQuantity = min(CartItem::MAX_QUANTITY, $item->quantity + $quantity);
                // C3: more units must reach the store cart too.
                $sync = CartSync::enabled() && $newQuantity !== $item->quantity;
                $item->update(['quantity' => $newQuantity] + ($sync ? ['sync_status' => 'pending', 'sync_note' => null] : []));
            } else {
                $sync = true;   // a new row starts `pending`
                $item = $cart->items()->create([
                    'store_id' => $data['store_id'],
                    'store_name' => $data['store_name'] ?? null,
                    'product_url' => $url,
                    'product_url_hash' => $hash,
                    'title' => $data['title'],
                    'image_url' => isset($data['image_url']) ? trim($data['image_url']) : null,
                    'price' => $data['price'] ?? null,
                    'currency' => 'USD',
                    'quantity' => $quantity,
                    'variants' => $variants,
                    'variants_key' => $key,
                    'source' => $data['source'],
                    'saved_id' => $data['saved_id'] ?? null,
                ]);
            }

            $cart->touch();

            return [$cart, $item, $sync];
        });

        if ($sync) {
            CartSync::dispatch($cart->id, $item->store_id);
        }

        return response()->json(['data' => [
            'item' => $this->itemPayload($item->fresh()),
            'cart' => $this->cartPayload($cart->fresh()),
        ]], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'quantity' => 'sometimes|integer|min:1|max:' . CartItem::MAX_QUANTITY,
            'variants' => ['sometimes', 'array', $this->variantsRule()],
        ]);

        $item = $this->ownOpenItem($request->user()->id, $id);

        $changes = [];
        if (array_key_exists('quantity', $data)) {
            $changes['quantity'] = (int) $data['quantity'];
        }
        if (array_key_exists('variants', $data)) {
            $variants = $this->normalizeVariants($data['variants']);
            $key = CartItem::variantsKey($variants);

            // Changing the selection must not silently create a second line for
            // a combination that is already in the cart.
            $clash = CartItem::where('cart_id', $item->cart_id)
                ->where('id', '!=', $item->id)
                ->where('product_url_hash', $item->product_url_hash)
                ->where('variants_key', $key)
                ->exists();
            if ($clash) {
                throw ValidationException::withMessages([
                    'variants' => 'Ya tienes este producto con esas opciones en tu carrito.',
                ]);
            }

            $changes['variants'] = $variants;
            $changes['variants_key'] = $key;
        }

        if ($changes) {
            // C3: a changed quantity or selection must reach the store cart too.
            $item->fill($changes);
            $sync = CartSync::enabled() && $item->isDirty(['quantity', 'variants']);
            if ($sync) {
                $item->fill(['sync_status' => 'pending', 'sync_note' => null]);
            }
            $item->save();
            $item->cart->touch();
            if ($sync) {
                CartSync::dispatch($item->cart_id, $item->store_id);
            }
        }

        return response()->json(['data' => [
            'item' => $this->itemPayload($item->fresh()),
            'cart' => $this->cartPayload($item->cart->fresh()),
        ]]);
    }

    public function destroyItem(Request $request, int $id): JsonResponse
    {
        $item = $this->ownOpenItem($request->user()->id, $id);
        $cart = $item->cart;

        $item->delete();
        $cart->touch();

        return response()->json(['data' => ['cart' => $this->cartPayload($cart->fresh())]]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            $cart = Cart::where('user_id', $user->id)
                ->where('status', Cart::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            $items = $cart ? $cart->items()->get() : collect();
            if ($items->isEmpty()) {
                DB::rollBack();

                throw ValidationException::withMessages([
                    'cart' => 'Tu carrito está vacío.',
                ]);
            }

            $pr = $this->intake->create(
                $user,
                $items->map(fn (CartItem $item) => [
                    'product_name' => $item->title,
                    'product_url' => $item->product_url,
                    'product_image_url' => $item->image_url,
                    'price' => $item->price,
                    'quantity' => $item->quantity,
                    'options' => $this->optionsFor($item->variants ?? []),
                    'notes' => 'Tienda: ' . ($item->store_name ?: $item->store_id),
                ])->all(),
                $cart->conversation_id,
                'usd',
                $data['notes'] ?? null,
            );

            // Every cart item has a title, so this cannot be null; guard anyway
            // rather than finalize a cart into nothing.
            if ($pr === null) {
                DB::rollBack();

                throw ValidationException::withMessages(['cart' => 'Tu carrito está vacío.']);
            }

            $cart->update([
                'status' => Cart::STATUS_FINALIZED,
                'active_slot' => null,
                'purchase_request_id' => $pr->id,
            ]);

            // C5 (testers only): each store's real checkout total, then the invoice goes out automatically.
            $quoting = \App\Services\CartQuotes::enabledFor($user);
            if ($quoting) {
                \App\Services\CartQuotes::start($cart, $pr);
            }

            DB::commit();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Cart finalize failed: ' . $e->getMessage(), ['user_id' => $user->id]);

            return response()->json([
                'message' => 'No pudimos enviar tu carrito. Intenta de nuevo.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        // While the agent quotes, the customer hears nothing yet: their one email comes when it is done —
        // the invoice with the real totals, or "received" when the team has to quote (CartQuotes::maybeInvoice).
        $this->intake->notifyCreated($pr, $user, customer: ! $quoting);

        return response()->json(['data' => [
            'purchase_request_id' => $pr->id,
            'request_number' => $pr->request_number,
            'cart' => $this->cartPayload($cart->fresh()),
        ]], 201);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function openCart(int $userId): ?Cart
    {
        return Cart::where('user_id', $userId)->where('status', Cart::STATUS_OPEN)->first();
    }

    /**
     * The user's open cart, locked, created if missing. Two concurrent first-adds
     * both miss the SELECT; the unique (user_id, active_slot) index lets exactly
     * one INSERT win, and the loser re-reads the winner's row. The insert runs in
     * a savepoint so the losing INSERT does not poison the outer transaction
     * (Postgres-style) — cheap insurance.
     */
    private function openCartForWrite(int $userId): Cart
    {
        $cart = Cart::where('user_id', $userId)->where('status', Cart::STATUS_OPEN)->lockForUpdate()->first();
        if ($cart) {
            return $cart;
        }

        try {
            return DB::transaction(fn () => Cart::create([
                'user_id' => $userId,
                'status' => Cart::STATUS_OPEN,
                'active_slot' => 1,
            ]));
        } catch (QueryException $e) {
            $cart = Cart::where('user_id', $userId)->where('status', Cart::STATUS_OPEN)->lockForUpdate()->first();
            if (! $cart) {
                throw $e;
            }

            return $cart;
        }
    }

    /** An item of THIS user's OPEN cart, or 404 — never reveals other carts. */
    private function ownOpenItem(int $userId, int $id): CartItem
    {
        return CartItem::where('id', $id)
            ->whereHas('cart', fn ($q) => $q->where('user_id', $userId)->where('status', Cart::STATUS_OPEN))
            ->firstOrFail();
    }

    /** Validates `variants` as an object of string → string, ≤6 keys, keys ≤40, values ≤120. */
    private function variantsRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! is_array($value)) {
                return; // the `array` rule reports it
            }
            if ($value !== [] && array_is_list($value)) {
                $fail('Las opciones deben ser un objeto {nombre: valor}.');

                return;
            }
            if (count($value) > 6) {
                $fail('Máximo 6 opciones por producto.');

                return;
            }
            foreach ($value as $k => $v) {
                $k = trim((string) $k);
                if ($k === '' || mb_strlen($k) > 40) {
                    $fail('Cada opción necesita un nombre de máximo 40 caracteres.');

                    return;
                }
                if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
                    $fail("La opción {$k} debe ser texto.");

                    return;
                }
                if (mb_strlen(trim((string) $v)) > 120) {
                    $fail("La opción {$k} admite máximo 120 caracteres.");

                    return;
                }
            }
        };
    }

    /** Trimmed string keys and values, exactly as the customer labeled them. */
    private function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $k => $v) {
            $out[trim((string) $k)] = trim((string) $v);
        }

        return $out;
    }

    /** Variants → PR item options: size→Talla, color→Color, anything else as-is. */
    private function optionsFor(array $variants): ?array
    {
        $options = [];
        foreach ($variants as $k => $v) {
            $options[self::OPTION_LABELS[mb_strtolower((string) $k)] ?? $k] = $v;
        }

        return $options ?: null;
    }

    private function itemPayload(CartItem $item): array
    {
        return [
            'id' => $item->id,
            'store_id' => $item->store_id,
            'store_name' => $item->store_name,
            'product_url' => $item->product_url,
            'title' => $item->title,
            'image_url' => $item->image_url,
            'price' => $item->price === null ? null : (float) $item->price,
            'currency' => $item->currency,
            'quantity' => (int) $item->quantity,
            'variants' => (object) ($item->variants ?? []),
            'source' => $item->source,
            'saved_id' => $item->saved_id,
            'sync_status' => $item->sync_status,
            'sync_note' => $item->sync_note,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    /**
     * The cart as the app renders it. A finalized cart is not "the cart" any
     * more, so anything but an open one renders as the empty open cart.
     */
    private function cartPayload(?Cart $cart): array
    {
        if (! $cart || $cart->status !== Cart::STATUS_OPEN) {
            return [
                'id' => null,
                'status' => Cart::STATUS_OPEN,
                'conversation_id' => null,
                'items' => [],
                'stores' => [],
                'item_count' => 0,
                'subtotal' => 0,
                'has_unpriced' => false,
                'updated_at' => null,
                'sync_enabled' => \App\Services\CartSync::enabled(),
                'live_sessions' => [],
            ];
        }

        $items = $cart->items()->get();

        // Grouped by store in first-added order (items are ordered by id).
        $stores = [];
        foreach ($items as $item) {
            $s = $stores[$item->store_id] ??= [
                'store_id' => $item->store_id,
                'store_name' => $item->store_name,
                'item_count' => 0,
                'subtotal' => 0.0,
                'has_unpriced' => false,
            ];
            $s['store_name'] ??= $item->store_name;
            $s['item_count'] += (int) $item->quantity;
            $s['subtotal'] += $item->price === null ? 0 : (float) $item->price * (int) $item->quantity;
            $s['has_unpriced'] = $s['has_unpriced'] || $item->price === null;
            $stores[$item->store_id] = $s;
        }
        $stores = array_values(array_map(function ($s) {
            $s['subtotal'] = round($s['subtotal'], 2);

            return $s;
        }, $stores));

        return [
            'id' => $cart->id,
            'status' => $cart->status,
            'conversation_id' => $cart->conversation_id,
            'items' => $items->map(fn (CartItem $i) => $this->itemPayload($i))->all(),
            'stores' => $stores,
            'item_count' => (int) $items->sum('quantity'),
            'subtotal' => round(array_sum(array_column($stores, 'subtotal')), 2),
            'has_unpriced' => (bool) array_filter(array_column($stores, 'has_unpriced')),
            'updated_at' => $cart->updated_at?->toISOString(),
            // C3: whether adds are being mirrored into the real store carts. With it
            // off the app never waits on (or polls for) a store sync that won't run.
            'sync_enabled' => \App\Services\CartSync::enabled(),
            // C4 (watch in chat): the store browsers the agent is running for this cart right
            // now, so the chat can show them live (view-only ticket via /live-shopping/sessions/{id}/ticket).
            'live_sessions' => $this->liveSessions($cart),
        ];
    }

    /** Running (or starting) cart sessions of this cart, newest first: they hold the store's active key. */
    private function liveSessions(Cart $cart): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('live_shopping_sessions', 'cart_id')) {
            return [];
        }

        return \App\Models\LiveShoppingSession::where('cart_id', $cart->id)
            ->whereNotNull('cart_active_key')
            ->whereNotNull('engine_session_id')
            ->orderByDesc('id')
            ->get(['id', 'store_id', 'status'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'store_id' => $s->store_id,
                'store_name' => $cart->items()->where('store_id', $s->store_id)->value('store_name'),
                'status' => $s->status,
            ])
            ->values()
            ->all();
    }
}
