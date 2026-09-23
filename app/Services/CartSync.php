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
    public const FAILED_NOTE = 'No se pudo agregar en la tienda; se reintentará';

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
     * retry note. Then, if that store still has pending items, sync again.
     *
     * Runs inside the caller's terminal transaction; the caller writes the
     * session's own terminal columns (and nulls cart_active_key).
     */
    public static function applyTerminal(LiveShoppingSession $session, string $outcome, ?array $cart): void
    {
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
                    $item->forceFill(['sync_status' => $line['state'], 'sync_note' => $line['note'] ?? null])->save();
                }
            }
            foreach ($items as $item) {
                $item->forceFill(['sync_status' => 'pending', 'sync_note' => null])->save();
            }
        } else {
            foreach ($items as $item) {
                $item->forceFill(['sync_status' => 'failed', 'sync_note' => self::FAILED_NOTE])->save();
            }
        }

        if ($session->cart_id
            && Cart::where('id', $session->cart_id)->where('status', Cart::STATUS_OPEN)->exists()
            && CartItem::where('cart_id', $session->cart_id)->where('store_id', $session->store_id)
                ->where('sync_status', 'pending')->exists()) {
            self::dispatch($session->cart_id, $session->store_id);
        }
    }

    /**
     * A cart session's webhook may be delayed or lost: read the engine's status
     * and, if it is terminal, run the SAME projector the webhook would (through
     * a deterministic local receipt), so the active key is released and the
     * items are settled exactly once whichever path arrives first.
     */
    public static function reconcileStatus(LiveShoppingSession $session, LiveShoppingEngine $engine): void
    {
        if ($session->isTerminal() || ! $session->engine_session_id) {
            return;
        }

        try {
            $remote = $engine->sessionStatus($session->engine_session_id, true);
        } catch (LiveShoppingEngineException $e) {
            return; // transport/schema failure is not authority to mutate local state
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
