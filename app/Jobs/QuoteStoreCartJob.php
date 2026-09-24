<?php

namespace App\Jobs;

use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\StoreQuote;
use App\Services\CartQuotes;
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

/**
 * C5 — take one store's checkout quote for a finalized cart: an engine `cart`
 * session with operation `quote` on the customer's own store cart, which it
 * checks out as a guest to the warehouse and prices (never pays).
 *
 * It shares the cart × store key with cart sync, so a quote never runs on the
 * same store browser as a sync still in flight. A held key or a busy engine
 * means "not now": the row goes back to pending and the job retries after
 * 30–60 s. The engine runs one session at a time, so a cart with several
 * stores quotes them one after another; each quote terminal also re-dispatches
 * the cart's next pending store (CartQuotes::applyTerminal).
 */
class QuoteStoreCartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ~30 min of busy retries: a multi-store cart waits its turn. */
    public int $tries = 40;

    private const RETRYABLE_CODES = [
        'engine_busy', 'rate_limited', 'service_closing', 'not_accepting', 'controller_busy',
        'worker_start_failed', 'worker_ready_timeout', 'create_response_timeout', 'internal_error',
    ];

    public function __construct(public int $storeQuoteId)
    {
    }

    public function handle(LiveShoppingEngine $engine): void
    {
        if (! CartQuotes::configured() || ! $engine->enabled()) {
            return;
        }

        $claim = DB::transaction(fn () => $this->claim());
        if ($claim === 'busy') {
            $this->retryLater('key_held');

            return;
        }
        if ($claim === null) {
            return;
        }
        [$quote, $session, $selections, $cart] = $claim;

        try {
            $engineSession = $engine->createSession(
                $session->id,
                $cart->conversation_id,
                $quote->store_id,
                'cart',
                LiveShoppingSession::KIND_CART,
                [
                    'cart_ref'     => 'cart-' . $cart->id,
                    'customer_ref' => CartSync::customerRef($cart->user_id),
                    'operation'    => 'quote',
                    'selections'   => $selections,
                ],
            );
        } catch (LiveShoppingEngineException $e) {
            $retryable = $e->status === 503 || in_array($e->getMessage(), self::RETRYABLE_CODES, true);
            $this->abandon($quote, $session, $retryable, $e->getMessage());
            if ($retryable) {
                $this->retryLater($e->getMessage());
            } else {
                CartQuotes::afterQuoteSettled($quote->fresh());
            }

            return;
        }

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
            Log::warning('store quote create lost the race to persist', ['session_id' => $session->id]);
            $engine->cancelSessionQuietly($engineSession['id']);
        }
    }

    /**
     * Null: nothing to do (not pending, cart gone, no quotable items — the last
     * settles the row failed). 'busy': the store's cart key is held. Otherwise
     * the running row, its session and the selections to send.
     */
    private function claim(): array|string|null
    {
        $quote = StoreQuote::where('id', $this->storeQuoteId)->lockForUpdate()->first();
        if (! $quote || $quote->status !== StoreQuote::STATUS_PENDING) {
            return null;
        }
        $cart = $quote->cart;
        if (! $cart) {
            return null;
        }
        $key = CartSync::activeKey($cart->id, $quote->store_id);
        if (LiveShoppingSession::where('cart_active_key', $key)->exists()) {
            return 'busy';
        }

        $selections = CartItem::where('cart_id', $cart->id)
            ->where('store_id', $quote->store_id)
            ->orderBy('id')
            ->limit(SyncStoreCartJob::MAX_SELECTIONS)
            ->get()
            ->map(fn (CartItem $item) => SyncStoreCartJob::selection($item))
            ->filter()
            ->values()
            ->all();
        if ($selections === []) {
            $quote->forceFill(['status' => StoreQuote::STATUS_FAILED, 'error_code' => 'no_quotable_items'])->save();
            DB::afterCommit(fn () => CartQuotes::afterQuoteSettled($quote->fresh()));

            return null;
        }

        try {
            $session = DB::transaction(fn () => LiveShoppingSession::create([
                'user_id'         => $cart->user_id,
                'conversation_id' => $cart->conversation_id,
                'status'          => LiveShoppingSession::STATUS_PENDING,
                'store_id'        => $quote->store_id,
                'kind'            => LiveShoppingSession::KIND_CART,
                'stores'          => [['id' => $quote->store_id]],
                'objective'       => 'cart',
                'cart_id'         => $cart->id,
                'cart_active_key' => $key,
            ]));
        } catch (QueryException $e) {
            $message = $e->getMessage();
            if (($e->getCode() === '23000' || str_contains($message, '1062')) && str_contains($message, 'cart_active_key')) {
                return 'busy';
            }
            throw $e;
        }

        $quote->forceFill([
            'status' => StoreQuote::STATUS_RUNNING,
            'live_shopping_session_id' => $session->id,
            'attempts' => $quote->attempts + 1,
            'error_code' => null,
        ])->save();

        return [$quote, $session, $selections, $cart];
    }

    /** The create did not start a session: end the row, release the key, and put the quote back or fail it. */
    private function abandon(StoreQuote $quote, LiveShoppingSession $session, bool $retryable, string $code): void
    {
        DB::transaction(function () use ($quote, $session, $retryable, $code) {
            $fresh = LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();
            if ($fresh && ! $fresh->isTerminal() && $fresh->cart_active_key !== null) {
                $fresh->forceFill([
                    'status'          => LiveShoppingSession::STATUS_FAILED,
                    'error_code'      => preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : 'engine_unavailable',
                    'cart_active_key' => null,
                ])->save();
            }
            StoreQuote::where('id', $quote->id)->where('status', StoreQuote::STATUS_RUNNING)->update([
                'status'     => $retryable ? StoreQuote::STATUS_PENDING : StoreQuote::STATUS_FAILED,
                'error_code' => preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : 'engine_unavailable',
                'updated_at' => now(),
            ]);
        });
    }

    /** Retry after 30–60 s; after the last attempt the store is failed (manual quote). */
    private function retryLater(string $reason): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release(random_int(30, 60));

            return;
        }
        $quote = StoreQuote::find($this->storeQuoteId);
        if ($quote && $quote->status === StoreQuote::STATUS_PENDING) {
            $quote->forceFill(['status' => StoreQuote::STATUS_FAILED, 'error_code' => 'gave_up_' . substr($reason, 0, 50)])->save();
            Log::warning('store quote gave up after retries', ['store_quote_id' => $quote->id, 'reason' => $reason]);
            CartQuotes::afterQuoteSettled($quote->fresh());
        }
    }
}
