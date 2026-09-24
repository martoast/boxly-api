<?php

namespace App\Jobs;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Services\CartSync;
use App\Services\LiveShoppingEngine;
use App\Services\LiveShoppingEngineException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * C3 — put a cart's pending items for ONE store into the customer's real store
 * cart through an engine `cart` session (operation `add`).
 *
 * At most one sync per cart × store runs at a time: the session row claims
 * `cart_active_key` at INSERT and the unique index arbitrates. A job that finds
 * the key held simply returns — that session's terminal re-dispatches this job
 * while pending items remain. A busy or unreachable engine puts the items back
 * to `pending` and retries after 30–60 s, a bounded number of times.
 */
class SyncStoreCartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bounded: after the last busy answer the items stay pending for the next add to retry. */
    public int $tries = 5;

    public const MAX_SELECTIONS = 20;

    /** Engine refusals that mean "not now", not "never" (plus every 503: timeout, 5xx, unreadable). */
    private const RETRYABLE_CODES = [
        'engine_busy', 'rate_limited', 'service_closing', 'not_accepting', 'controller_busy',
        'worker_start_failed', 'worker_ready_timeout', 'create_response_timeout', 'internal_error',
    ];

    public function __construct(public int $cartId, public string $storeId)
    {
    }

    public function handle(LiveShoppingEngine $engine): void
    {
        if (! CartSync::enabled() || ! $engine->enabled()
            || ! Schema::hasColumn('live_shopping_sessions', 'cart_active_key')) {
            return;
        }

        $claim = DB::transaction(fn () => $this->claim());
        if ($claim === null) {
            return;
        }
        [$cart, $session, $selections] = $claim;

        try {
            $engineSession = $engine->createSession(
                $session->id,
                $cart->conversation_id,
                $this->storeId,
                'cart',
                LiveShoppingSession::KIND_CART,
                [
                    'cart_ref'     => 'cart-' . $cart->id,
                    'customer_ref' => CartSync::customerRef($cart->user_id),
                    'operation'    => 'add',
                    'selections'   => $selections,
                ],
            );
        } catch (LiveShoppingEngineException $e) {
            $retryable = $e->status === 503 || in_array($e->getMessage(), self::RETRYABLE_CODES, true);
            $this->abandon($session, $retryable, $e->getMessage());

            if ($retryable && $this->attempts() < $this->tries) {
                $this->release(random_int(30, 60));
            } elseif ($retryable) {
                Log::warning('cart sync gave up after retries; items stay pending', [
                    'cart_id' => $this->cartId, 'store_id' => $this->storeId,
                ]);
            }

            return;
        }

        // Compare-and-set, exactly like the live-shopping create: the expiry
        // sweep may have failed this row while the create was in flight, and a
        // blind save would resurrect it outside the one-sync-per-store key.
        $claimed = LiveShoppingSession::where('id', $session->id)
            ->where('status', LiveShoppingSession::STATUS_PENDING)
            ->whereNotNull('cart_active_key')
            ->whereNull('expires_at')
            ->update([
                'engine_session_id' => $engineSession['id'],
                'status'            => $engineSession['status'] === 'running'
                    ? LiveShoppingSession::STATUS_RUNNING
                    : LiveShoppingSession::STATUS_PENDING,
                'expires_at'        => Carbon::parse($engineSession['expires_at']),
                'latest_seq'        => $engineSession['latest_seq'],
                'updated_at'        => now(),
            ]);

        if ($claimed === 0) {
            Log::warning('cart sync create lost the race to persist', ['session_id' => $session->id]);
            $engine->cancelSessionQuietly($engineSession['id']);
        }
    }

    /**
     * Inside one transaction: the open cart, no sync already holding the key,
     * its pending items for this store (≤20) — then the session row claims the
     * key and those items become `syncing`. Null when there is nothing to do.
     */
    private function claim(): ?array
    {
        $cart = Cart::where('id', $this->cartId)->where('status', Cart::STATUS_OPEN)->first();
        if (! $cart) {
            return null;
        }
        $key = CartSync::activeKey($cart->id, $this->storeId);
        if (LiveShoppingSession::where('cart_active_key', $key)->exists()) {
            return null;   // a sync is pending/running; its terminal re-dispatches
        }

        $items = CartItem::where('cart_id', $cart->id)
            ->where('store_id', $this->storeId)
            ->where('sync_status', 'pending')
            ->orderBy('id')
            ->limit(self::MAX_SELECTIONS)
            ->lockForUpdate()
            ->get();

        $selections = [];
        $ids = [];
        foreach ($items as $item) {
            $selection = self::selection($item);
            if ($selection === null) {
                // A link the engine would refuse can never sync; say so instead
                // of retrying it forever.
                $item->forceFill(['sync_status' => 'failed', 'sync_note' => 'Este enlace no se puede agregar en la tienda'])->save();

                continue;
            }
            $selections[] = $selection;
            $ids[] = $item->id;
        }
        if ($selections === []) {
            return null;
        }

        try {
            // Savepoint: a losing INSERT must not poison the outer transaction.
            $session = DB::transaction(fn () => LiveShoppingSession::create([
                'user_id'         => $cart->user_id,
                'conversation_id' => $cart->conversation_id,
                'status'          => LiveShoppingSession::STATUS_PENDING,
                'store_id'        => $this->storeId,
                'kind'            => LiveShoppingSession::KIND_CART,
                'stores'          => [['id' => $this->storeId]],
                'objective'       => 'cart',
                'cart_id'         => $cart->id,
                'cart_active_key' => $key,
                // Deliberately no active_slot: a cart sync never blocks the
                // customer's own live session.
            ]));
        } catch (QueryException $e) {
            $message = $e->getMessage();
            if (($e->getCode() === '23000' || str_contains($message, '1062')) && str_contains($message, 'cart_active_key')) {
                return null;   // a concurrent job won the key
            }
            throw $e;
        }

        CartItem::whereIn('id', $ids)->update(['sync_status' => 'syncing', 'sync_note' => null, 'updated_at' => now()]);

        return [$cart, $session, $selections];
    }

    /**
     * The contract's selection for one item, or null when its link cannot be
     * sent (not https, credentials, or too long). A fragment is dropped; the
     * variant keys are folded onto the engine's key alphabet.
     */
    public static function selection(CartItem $item): ?array
    {
        $url = explode('#', trim($item->product_url), 2)[0];
        $parts = parse_url($url);
        if (strlen($url) > 2048 || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $variants = [];
        foreach ((array) ($item->variants ?? []) as $k => $v) {
            $k = substr(trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii((string) $k)), '_'), 0, 40);
            if ($k === '' || count($variants) >= 6) {
                continue;
            }
            $variants[$k] = mb_substr((string) $v, 0, 120);
        }

        return [
            'selection_id' => 'ci-' . $item->id,
            'url'          => $url,
            'title'        => mb_substr($item->title, 0, 300),
            'quantity'     => max(1, min(20, (int) $item->quantity)),
            'variants'     => (object) $variants,
        ];
    }

    /**
     * The create did not start a session: end the row (releasing the key) and
     * hand its items back — `pending` when the engine only said "not now",
     * `failed` with the retry note when it refused outright.
     */
    private function abandon(LiveShoppingSession $session, bool $retryable, string $code): void
    {
        DB::transaction(function () use ($session, $retryable, $code) {
            $fresh = LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isTerminal() || $fresh->cart_active_key === null) {
                return;   // the expiry sweep already settled it
            }

            CartItem::where('cart_id', $this->cartId)
                ->where('store_id', $this->storeId)
                ->where('sync_status', 'syncing')
                ->update($retryable
                    ? ['sync_status' => 'pending', 'sync_note' => null, 'updated_at' => now()]
                    : ['sync_status' => 'failed', 'sync_note' => CartSync::FAILED_NOTE, 'updated_at' => now()]);

            $fresh->forceFill([
                'status'          => LiveShoppingSession::STATUS_FAILED,
                'error_code'      => preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : 'engine_unavailable',
                'cart_active_key' => null,
            ])->save();
        });
    }
}
