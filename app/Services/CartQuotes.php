<?php

namespace App\Services;

use App\Jobs\QuoteStoreCartJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StoreQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C5 — Finalizar → a checkout quote per store (engine) → automatic invoice.
 *
 * At finalize, each store of the cart gets a StoreQuote row and a
 * QuoteStoreCartJob. Each quote's terminal lands here (via CartSync's cart
 * terminal); when every store of the purchase request is settled, the invoice
 * goes out automatically if every store has a billable total, within the
 * internal-test guard rails. Otherwise the request stays pending_review with a
 * note, for the shopping team's manual quote as today.
 */
class CartQuotes
{
    /** The feature is switched on and the engine can take cart sessions. */
    public static function configured(): bool
    {
        return (bool) config('services.live_shopping_engine.cart_quotes')
            && CartSync::enabled();
    }

    /** Quotes run for this customer (internal testers only while the product is evaluated). */
    public static function enabledFor(?User $user): bool
    {
        return self::configured() && BoxlyBeta::allows($user);
    }

    /** At finalize (inside its transaction): one row + one job per store of the cart. */
    public static function start(Cart $cart, PurchaseRequest $pr): void
    {
        $stores = CartItem::where('cart_id', $cart->id)
            ->select('store_id', DB::raw('MAX(store_name) as store_name'))
            ->groupBy('store_id')
            ->orderBy('store_id')
            ->get();
        foreach ($stores as $store) {
            $quote = StoreQuote::firstOrCreate(
                ['purchase_request_id' => $pr->id, 'store_id' => $store->store_id],
                ['cart_id' => $cart->id, 'store_name' => $store->store_name, 'status' => StoreQuote::STATUS_PENDING],
            );
            self::dispatch($quote);
        }
    }

    public static function dispatch(StoreQuote $quote): void
    {
        QuoteStoreCartJob::dispatch($quote->id)
            ->onConnection(config('services.live_shopping_engine.cart_sync_connection'))
            ->afterCommit();
    }

    /**
     * A quote session's terminal (called by CartSync::applyTerminal, inside the
     * caller's terminal transaction): the row takes the engine's verdict and
     * money, the cart items take their line states.
     */
    public static function applyTerminal(LiveShoppingSession $session, StoreQuote $quote, string $outcome, ?array $cart): void
    {
        $quote = StoreQuote::where('id', $quote->id)->lockForUpdate()->first();
        if (! $quote || $quote->isTerminal()) {
            return;
        }
        $q = ($outcome === LiveShoppingSession::STATUS_COMPLETED && is_array($cart) && ($cart['operation'] ?? null) === 'quote')
            ? ($cart['quote'] ?? null) : null;

        if (is_array($q)) {
            $fill = [
                'status'               => $q['verdict'],
                'currency'             => $q['currency'],
                'estimated'            => $q['estimated'],
                'destination_verified' => $q['destination_verified'],
                'checkout_stage'       => $q['checkout_stage'],
                'evidence'             => $q['evidence'],
                'observed_at'          => $q['observed_at'],
                'error_code'           => $q['verdict'] === 'failed' ? 'quote_unverified' : null,
            ];
            foreach (StoreQuote::MONEY as $part) {
                $fill["{$part}_cents"] = $q[$part];
            }
            $quote->forceFill($fill)->save();
        } else {
            $quote->forceFill(['status' => StoreQuote::STATUS_FAILED, 'error_code' => $session->error_code ?: 'quote_' . $outcome])->save();
        }

        // Item states from the lines, as for a sync (the quote also puts missing items in the store cart).
        foreach ((is_array($cart) ? ($cart['lines'] ?? []) : []) as $line) {
            $id = substr((string) ($line['selection_id'] ?? ''), 3);
            if (ctype_digit($id)) {
                CartItem::where('id', (int) $id)->where('cart_id', $quote->cart_id)
                    ->update(['sync_status' => $line['state'], 'sync_note' => $line['note'] ?? null, 'updated_at' => now()]);
            }
        }

        DB::afterCommit(fn () => self::afterQuoteSettled($quote->fresh()));
    }

    /** After any store settles: start the cart's next pending store, and invoice when all are settled. */
    public static function afterQuoteSettled(?StoreQuote $quote): void
    {
        if (! $quote) {
            return;
        }
        StoreQuote::where('purchase_request_id', $quote->purchase_request_id)
            ->where('status', StoreQuote::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->each(fn (StoreQuote $next) => self::dispatch($next));

        self::maybeInvoice($quote->purchase_request_id);
    }

    /**
     * Every store settled → the automatic invoice, or the manual fallback with a
     * note saying why. Idempotent: only a pending_review request with no
     * invoice is touched, under a row lock.
     */
    public static function maybeInvoice(int $purchaseRequestId): void
    {
        // Everything under the request's row lock, Stripe calls included (as the
        // manual createQuote does): two stores settling at once must never send
        // two invoices — the second caller waits, then sees it quoted.
        try {
            DB::transaction(function () use ($purchaseRequestId) {
                $pr = PurchaseRequest::where('id', $purchaseRequestId)->lockForUpdate()->first();
                if (! $pr || $pr->status !== PurchaseRequest::STATUS_PENDING_REVIEW || $pr->stripe_invoice_id) {
                    return;
                }
                $quotes = StoreQuote::where('purchase_request_id', $pr->id)->orderBy('store_id')->get();
                if ($quotes->isEmpty() || $quotes->contains(fn (StoreQuote $q) => ! $q->isTerminal())) {
                    return;
                }
                $why = self::manualReason($quotes);
                if ($why !== null) {
                    self::noteManual($pr, "Cotización automática no enviada: {$why}. Cotizar manualmente.");
                    Log::info('auto-quote fell back to manual', ['purchase_request_id' => $pr->id, 'why' => $why]);

                    return;
                }
                self::markUnavailableItems($pr, $quotes);
                app(StoreQuoteInvoice::class)->send($pr, $quotes);
            });
        } catch (\Throwable $e) {
            Log::error('automatic invoice failed; manual quote needed', ['purchase_request_id' => $purchaseRequestId, 'error' => $e->getMessage()]);
            $pr = PurchaseRequest::find($purchaseRequestId);
            if ($pr && ! $pr->stripe_invoice_id) {
                self::noteManual($pr, 'La factura automática falló (' . mb_substr($e->getMessage(), 0, 120) . '). Cotizar manualmente.');
            }
        }
    }

    private static function noteManual(PurchaseRequest $pr, string $text): void
    {
        $marker = '[auto-quote]';
        if (! str_contains((string) $pr->admin_notes, $marker)) {
            $pr->forceFill(['admin_notes' => trim(((string) $pr->admin_notes) . "\n{$marker} {$text}")])->save();
        }
    }

    /** Null when the quotes can be invoiced automatically; else why not (Spanish, for the team). */
    public static function manualReason($quotes): ?string
    {
        $failed = $quotes->filter(fn (StoreQuote $q) => ! in_array($q->status, StoreQuote::BILLABLE, true));
        if ($failed->isNotEmpty()) {
            return 'sin total verificado en ' . $failed->map(fn ($q) => $q->store_name ?: $q->store_id)->join(', ');
        }
        if ($quotes->contains(fn (StoreQuote $q) => $q->currency !== 'USD' || $q->total_cents === null || $q->total_cents <= 0)) {
            return 'moneda o total inesperado';
        }
        $maxStore = (int) round(config('services.live_shopping_engine.quote_max_store_usd', 1500) * 100);
        $maxOrder = (int) round(config('services.live_shopping_engine.quote_max_order_usd', 3000) * 100);
        $over = $quotes->first(fn (StoreQuote $q) => $q->total_cents > $maxStore);
        if ($over) {
            return 'el total de ' . ($over->store_name ?: $over->store_id) . ' supera el límite automático';
        }
        if ($quotes->sum('total_cents') > $maxOrder) {
            return 'el total del pedido supera el límite automático';
        }

        return null;
    }

    /** A partial quote's unavailable lines: the matching purchase request items are not billed. */
    private static function markUnavailableItems(PurchaseRequest $pr, $quotes): void
    {
        $urls = CartItem::whereIn('cart_id', $quotes->pluck('cart_id')->unique())
            ->where('sync_status', 'unavailable')
            ->pluck('product_url')
            ->all();
        if ($urls !== []) {
            PurchaseRequestItem::where('purchase_request_id', $pr->id)->whereIn('product_url', $urls)
                ->update(['stock_status' => PurchaseRequestItem::STOCK_UNAVAILABLE]);
        }
    }
}
