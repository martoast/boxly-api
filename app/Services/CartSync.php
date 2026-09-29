<?php

namespace App\Services;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Jobs\SyncStoreCartJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;

/**
 * C3 — the Boxly cart mirrored into the customer's real store cart.
 *
 * The small shared pieces of the cart sync: the feature flag, the customer's
 * opaque engine reference, the after-commit trigger, and the one place a cart
 * session's terminal lands on its cart items (used by the webhook projector,
 * the status reconcile and the expiry sweep alike).
 */
class CartSync
{
    /** The note a failed sync leaves on the items it was carrying. */
    // Final: the automatic retry has already happened (a failed run is tried once more — see applyTerminal).
    public const FAILED_NOTE = 'No se pudo agregar en la tienda';

    /** How many failed agent runs a line gets before it is marked failed (the first is retried). */
    public const RUN_ATTEMPTS = 2;

    public static function enabled(): bool
    {
        return (bool) config('services.live_shopping_engine.cart_sync', false);
    }

    /**
     * The engine's opaque, stable handle for a customer: a keyed hash of the
     * Boxly user id, so the engine can key a per-customer browser profile
     * without ever learning who the customer is.
     */
    public static function customerRef(int $userId): string
    {
        return 'cu-' . substr(hash_hmac('sha256', (string) $userId, (string) config('app.key')), 0, 32);
    }

    /** "<cart_id>:<store_id>" — held by the one pending/running sync of that cart × store. */
    public static function activeKey(int $cartId, string $storeId): string
    {
        return $cartId . ':' . $storeId;
    }

    /**
     * Sync this store of this cart once the current transaction commits; a no-op with the flag off.
     * Pinned to a real queue (`database`, like the webhook's result job): on the `sync` driver the
     * job's busy-engine `release()` is a silent no-op and the items would sit `pending` forever.
     */
    public static function dispatch(int $cartId, string $storeId): void
    {
        if (self::enabled()) {
            SyncStoreCartJob::dispatch($cartId, $storeId)->onConnection(config('services.live_shopping_engine.cart_sync_connection'))->afterCommit();
        }
    }

    /**
     * Land a cart session's terminal on its items. The session's items are the
     * cart's `syncing` items for its store: at most one sync per cart × store
     * holds the active key, and only it moves items to `syncing`.
     *
     * A completed result sets each reported line's state and note; an item it
     * does not mention goes back to `pending`. Anything else (failed, cancelled,
     * expired, or a completed result without `cart`) fails the items with the
     * retry note. Then every store of the cart that still has pending items
     * syncs again — not only this one: the engine just freed up, and another
     * store's job may have spent its busy retries while this one ran.
     *
     * Runs inside the caller's terminal transaction; the caller writes the
     * session's own terminal columns (and nulls cart_active_key).
     */
    public static function applyTerminal(LiveShoppingSession $session, string $outcome, ?array $cart): void
    {
        // A cart session ended: its engine slot is free for the next shopper in line (LiveQueue).
        if (LiveQueue::enabled()) {
            \App\Jobs\DrainLiveQueueJob::dispatch()->delay(now()->addSecond());
        }
        // C5: a quote session settles its store quote (and the items), not a sync.
        $quote = \App\Models\StoreQuote::where('live_shopping_session_id', $session->id)->first();
        if ($quote !== null) {
            CartQuotes::applyTerminal($session, $quote, $outcome, $cart);

            return;
        }

        $items = CartItem::where('cart_id', $session->cart_id)
            ->where('store_id', $session->store_id)
            ->where('sync_status', 'syncing')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($outcome === LiveShoppingSession::STATUS_COMPLETED && is_array($cart)) {
            foreach ($cart['lines'] ?? [] as $line) {
                $id = substr((string) ($line['selection_id'] ?? ''), 3);
                $item = ctype_digit($id) ? $items->pull((int) $id) : null;
                if ($item) {
                    $item->forceFill(['sync_status' => $line['state'], 'sync_note' => $line['note'] ?? null] + self::foundUrlFields($item, $line)
                        + self::variantPhotoFields($item, $line)
                        + (self::countsFailures() ? ['sync_failures' => 0] : []))->save();
                }
            }
            foreach ($items as $item) {
                $item->forceFill(['sync_status' => 'pending', 'sync_note' => null])->save();
            }
        } else {
            // A failed RUN (the model API erroring mid-add, a lost browser — live Lab Gymshark 2026-09-28: the item was
            // in the bag when the run died) is tried once more: the line goes back to pending and is re-dispatched
            // below. The second failure is final.
            foreach ($items as $item) {
                $failures = self::countsFailures() ? (int) $item->sync_failures + 1 : self::RUN_ATTEMPTS;
                $item->forceFill($failures < self::RUN_ATTEMPTS
                    ? ['sync_status' => 'pending', 'sync_note' => null, 'sync_failures' => $failures]
                    : ['sync_status' => 'failed', 'sync_note' => self::FAILED_NOTE] + (self::countsFailures() ? ['sync_failures' => $failures] : []))->save();
            }
        }

        if ($session->cart_id
            && Cart::where('id', $session->cart_id)->where('status', Cart::STATUS_OPEN)->exists()) {
            // A store whose sync is still running just returns (its key is held).
            CartItem::where('cart_id', $session->cart_id)->where('sync_status', 'pending')
                ->distinct()->orderBy('store_id')->pluck('store_id')
                ->each(fn (string $storeId) => self::dispatch($session->cart_id, $storeId));
        }
    }

    /** Whether cart_items has sync_failures (older schemas in tests do not). */
    private static function countsFailures(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('cart_items', 'sync_failures');
    }

    /**
     * The photo the engine read on the store's product page with the options picked, just before the add: it IS the
     * variant in the cart (Lab 2026-09-28: a Black beanie showed the catalog's brown default photo). It replaces the
     * line's photo when there is none or a colour was chosen; a product with no colour keeps the chat's photo.
     */
    public static function variantPhotoFields(CartItem $item, array $line): array
    {
        $photo = $line['image_url'] ?? null;
        if (($line['state'] ?? null) !== 'in_store_cart' || ! is_string($photo) || $photo === '') {
            return [];
        }
        $colour = collect((array) $item->variants)->keys()->contains(fn ($k) => preg_match('/colou?r|shade|finish|style/i', (string) $k));

        return blank($item->image_url) || $colour ? ['image_url' => $photo] : [];
    }

    /**
     * Search to cart: a `find` line the engine found now IS that product page — product_url (and its hash) become
     * it and find_query is cleared, so the quote prices exactly that page. Not when the same product (same page,
     * same variants) is already its own line: the unique key forbids two, and that line already carries the page.
     */
    public static function foundUrlFields(CartItem $item, array $line): array
    {
        $found = $line['found_url'] ?? null;
        if (! filled($item->find_query) || ! is_string($found) || $found === '') {
            return [];
        }
        $hash = CartItem::urlHash($found);
        $taken = CartItem::where('cart_id', $item->cart_id)->where('product_url_hash', $hash)
            ->where('variants_key', $item->variants_key)->where('id', '!=', $item->id)->exists();

        if ($taken) {
            return [];
        }
        // The page the search found also gives the line its photo when the chat had none (after this save).
        if (blank($item->image_url) && filled(config('services.catalog.url'))) {
            \App\Jobs\FillItemImageJob::dispatch('cart', $item->id)->afterCommit();
        }

        return ['product_url' => $found, 'product_url_hash' => $hash, 'find_query' => null];
    }

    /**
     * A cart session's webhook may be delayed or lost: read the engine's status
     * and, if it is terminal, run the SAME projector the webhook would (through
     * a deterministic local receipt), so the active key is released and the
     * items are settled exactly once whichever path arrives first.
     */
    /** Open carts' lines pending for over 2 minutes with no sync holding their store: dispatched again (reconcile). */
    public static function redispatchStalled(): void
    {
        CartItem::query()
            ->where('sync_status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->whereHas('cart', fn ($q) => $q->where('status', Cart::STATUS_OPEN))
            ->select('cart_id', 'store_id')->distinct()->limit(50)->get()
            ->reject(fn (CartItem $row) => LiveShoppingSession::where('cart_active_key', self::activeKey($row->cart_id, $row->store_id))->exists())
            ->each(fn (CartItem $row) => self::dispatch($row->cart_id, $row->store_id));
    }

    /** A cart/quote session the engine no longer knows: failed like a lost run, its cart × store key released. */
    private static function settleLost(LiveShoppingSession $session): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($session) {
            $fresh = LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isTerminal() || $fresh->cart_active_key === null) {
                return;
            }
            self::applyTerminal($fresh, LiveShoppingSession::STATUS_FAILED, null);
            $fresh->forceFill([
                'status'          => LiveShoppingSession::STATUS_FAILED,
                'error_code'      => 'engine_lost',
                'cart_active_key' => null,
            ])->save();
        });
    }

    public static function reconcileStatus(LiveShoppingSession $session, LiveShoppingEngine $engine): void
    {
        if ($session->isTerminal() || ! $session->engine_session_id) {
            return;
        }

        try {
            $remote = $engine->sessionStatus($session->engine_session_id, true);
        } catch (LiveShoppingEngineException $e) {
            // The engine ANSWERED that it has no such session: it restarted (its sessions live in memory) and the run
            // is gone for good (prod 2026-09-29: a lost Gymshark sync held the cart × store key, and every later add
            // for that store sat pending until the old deadline). Settle it now as a failed run — retried once.
            if ($e->status === 422 && $e->getMessage() === 'unknown_session') {
                self::settleLost($session);
            }

            return; // any other transport/schema failure is not authority to mutate local state
        }

        // A deleted thread nulls the local conversation mid-session; the engine
        // still carries the id it was created with, so only a live one is compared.
        if ($remote['id'] !== $session->engine_session_id
            || $session->conversation_id !== null && $remote['conversation_id'] !== (string) $session->conversation_id
            || $remote['store_id'] !== $session->store_id) {
            return;
        }
        if ($remote['status'] === 'running' && $session->status === LiveShoppingSession::STATUS_PENDING) {
            LiveShoppingSession::where('id', $session->id)
                ->where('status', LiveShoppingSession::STATUS_PENDING)
                ->update(['status' => LiveShoppingSession::STATUS_RUNNING, 'updated_at' => now()]);

            return;
        }
        if (! in_array($remote['status'], LiveShoppingSession::TERMINAL_STATUSES, true)
            || $session->latest_seq !== null && $remote['latest_seq'] <= $session->latest_seq) {
            return;
        }

        $payload = [
            'delivery_id' => 'status-reconcile-' . $remote['id'] . '-' . $remote['latest_seq'],
            'session_id' => $remote['id'],
            'conversation_id' => $remote['conversation_id'],
            'terminal_seq' => $remote['latest_seq'],
            'occurred_at' => now()->toIso8601String(),
            'result' => array_merge(
                ['outcome' => $remote['status'], 'error_code' => $remote['error_code']],
                $remote['cart'] !== null ? ['cart' => $remote['cart']] : []
            ),
            'assistant_part' => ['type' => 'tool-live_results', 'state' => 'output-available', 'output' => ['products' => []]],
        ];
        $receipt = LiveShoppingWebhookReceipt::firstOrCreate(
            ['delivery_id' => $payload['delivery_id']],
            [
                'content_sha256' => hash('sha256', json_encode($payload)), 'payload' => $payload,
                'status' => LiveShoppingWebhookReceipt::STATUS_RECEIVED,
                'terminal_seq' => $payload['terminal_seq'], 'outcome' => $remote['status'],
                'received_at' => now(),
            ]
        );

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
    }
}
