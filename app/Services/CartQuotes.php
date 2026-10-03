<?php

namespace App\Services;

use App\Jobs\QuoteStoreCartJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StoreQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    /** At finalize (inside its transaction): one row per store in the order the shopper added them, and only the first store starts. */
    public static function start(Cart $cart, PurchaseRequest $pr): void
    {
        $stores = CartItem::where('cart_id', $cart->id)
            ->select('store_id', DB::raw('MAX(store_name) as store_name'), DB::raw('MIN(id) as first_item_id'))
            ->groupBy('store_id')
            ->orderBy('first_item_id')
            ->get();
        foreach ($stores as $store) {
            StoreQuote::firstOrCreate(
                ['purchase_request_id' => $pr->id, 'store_id' => $store->store_id],
                ['cart_id' => $cart->id, 'store_name' => $store->store_name, 'status' => StoreQuote::STATUS_PENDING],
            );
        }
        self::dispatchNext($pr->id);
    }

    /**
     * One store at a time: start the request's first pending quote (by id) unless one is running or already in flight
     * (pending with dispatched_at set; the reconcile re-sends a stale one). The claim is a conditional UPDATE, so of
     * two racing callers only the winner dispatches.
     */
    public static function dispatchNext(int $purchaseRequestId): void
    {
        // Under the request's row lock, so a webhook settle and the reconcile cannot both pass the in-flight check.
        DB::transaction(function () use ($purchaseRequestId) {
            PurchaseRequest::where('id', $purchaseRequestId)->lockForUpdate()->first();
            $quotes = StoreQuote::where('purchase_request_id', $purchaseRequestId);
            $guarded = Schema::hasColumn('store_quotes', 'dispatched_at');
            if ((clone $quotes)->where(fn ($q) => $guarded
                ? $q->where('status', StoreQuote::STATUS_RUNNING)->orWhere(fn ($p) => $p->where('status', StoreQuote::STATUS_PENDING)->whereNotNull('dispatched_at'))
                : $q->where('status', StoreQuote::STATUS_RUNNING))->exists()) {
                return;
            }
            $next = $quotes->where('status', StoreQuote::STATUS_PENDING)->orderBy('id')->first();
            if (! $next) {
                return;
            }
            if ($guarded && StoreQuote::where('id', $next->id)->where('status', StoreQuote::STATUS_PENDING)->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]) === 0) {
                return;
            }
            self::dispatch($next);   // afterCommit: the job starts once the claim is committed
        });
    }

    /** Crash between a terminal and the next dispatch, or a lost queue job: send each stalled request's next store (every minute). */
    public static function reconcile(): void
    {
        if (! Schema::hasTable('store_quotes') || ! Schema::hasColumn('store_quotes', 'dispatched_at')) {
            return;
        }
        // Only live requests: pending_review without an invoice, and quotes that were dispatched or are recent (old
        // rows from before dispatched_at must never be revived).
        $ids = StoreQuote::where('status', StoreQuote::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNotNull('dispatched_at')->orWhere('created_at', '>=', now()->subDay()))
            ->whereIn('purchase_request_id', PurchaseRequest::where('status', PurchaseRequest::STATUS_PENDING_REVIEW)->whereNull('stripe_invoice_id')->select('id'))
            ->distinct()->pluck('purchase_request_id');
        foreach ($ids as $id) {
            if (StoreQuote::where('purchase_request_id', $id)->where('status', StoreQuote::STATUS_RUNNING)->exists()) {
                continue;
            }
            $first = StoreQuote::where('purchase_request_id', $id)->where('status', StoreQuote::STATUS_PENDING)->orderBy('id')->first();
            if ($first && $first->dispatched_at !== null && $first->dispatched_at->lt(now()->subMinutes(10))) {
                StoreQuote::where('id', $first->id)->where('status', StoreQuote::STATUS_PENDING)->update(['dispatched_at' => null]);
            }
            self::dispatchNext($id);
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
        self::dispatchNext($quote->purchase_request_id);

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
                $quotes = StoreQuote::where('purchase_request_id', $pr->id)->orderBy('id')->get();
                if ($quotes->isEmpty() || $quotes->contains(fn (StoreQuote $q) => ! $q->isTerminal())) {
                    return;
                }
                $why = self::manualReason($quotes);
                if ($why !== null) {
                    $first = self::noteManual($pr, "Cotización automática no enviada: {$why}. Cotizar manualmente.");
                    Log::info('auto-quote fell back to manual', ['purchase_request_id' => $pr->id, 'why' => $why]);
                    if ($first) {
                        DB::afterCommit(fn () => self::notifyReceived($pr));
                    }

                    return;
                }
                self::markUnavailableItems($pr, $quotes);
                // Only the stores that verified are invoiced.
                app(StoreQuoteInvoice::class)->send($pr, $quotes->filter(fn (StoreQuote $q) => in_array($q->status, StoreQuote::BILLABLE, true))->values());
            });
        } catch (\Throwable $e) {
            Log::error('automatic invoice failed; manual quote needed', ['purchase_request_id' => $purchaseRequestId, 'error' => $e->getMessage()]);
            $pr = PurchaseRequest::find($purchaseRequestId);
            if ($pr && ! $pr->stripe_invoice_id) {
                if (self::noteManual($pr, 'La factura automática falló (' . mb_substr($e->getMessage(), 0, 120) . '). Cotizar manualmente.')) {
                    self::notifyReceived($pr);
                }
            }
        }
    }

    /** The team quotes it by hand: now the customer gets the usual "request received" email (held at finalize). */
    private static function notifyReceived(PurchaseRequest $pr): void
    {
        if ($pr->user) {
            app(PurchaseRequestIntake::class)->notifyCustomer($pr, $pr->user);
        }
    }

    /** True when this is the first manual-fallback note (so the fallback email goes out once). */
    private static function noteManual(PurchaseRequest $pr, string $text): bool
    {
        $marker = '[auto-quote]';
        if (str_contains((string) $pr->admin_notes, $marker)) {
            return false;
        }
        $pr->forceFill(['admin_notes' => trim(((string) $pr->admin_notes) . "\n{$marker} {$text}")])->save();

        return true;
    }

    /** Null when the quotes can be invoiced automatically; else why not (Spanish, for the team). */
    public static function manualReason($quotes): ?string
    {
        // THE STORES THAT VERIFIED ARE INVOICED (Alex, 2026-09-28: "invoice the stores that verified"): a store without
        // a verified total drops out of the invoice (its items are marked unavailable), and only when NO store verified
        // does the team quote it by hand.
        $billable = $quotes->filter(fn (StoreQuote $q) => in_array($q->status, StoreQuote::BILLABLE, true));
        if ($billable->isEmpty()) {
            return 'sin total verificado en ' . $quotes->map(fn ($q) => $q->store_name ?: $q->store_id)->join(', ');
        }
        $quotes = $billable;
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

    /**
     * The shopper's final summary (customer payload): null until every store settled. Commission and total come from
     * the same function as the invoice (StoreQuoteInvoice::totals), so they cannot drift.
     */
    public static function checkoutSummary(PurchaseRequest $pr): ?array
    {
        if (! Schema::hasTable('store_quotes')) {
            return null;
        }
        $quotes = StoreQuote::where('purchase_request_id', $pr->id)->orderBy('id')->get();
        if ($quotes->isEmpty() || $quotes->contains(fn (StoreQuote $q) => ! $q->isTerminal())) {
            return null;
        }
        $totals = StoreQuoteInvoice::totals($quotes);
        $manual = ! $pr->stripe_invoice_id && self::manualReason($quotes) !== null;

        return [
            'invoice_mode'   => $manual ? 'manual' : 'auto',
            // Never the internal reason (limits, store names): the customer only needs to know the team confirms.
            'manual_reason'  => $manual ? 'Nuestro equipo te confirmará el total' : null,
            'stores' => $quotes->map(function (StoreQuote $q) {
                $included = in_array($q->status, StoreQuote::BILLABLE, true);

                return [
                    'store_id'   => $q->store_id,
                    'store_name' => $q->store_name,
                    'status'     => $q->status,
                    'included'   => $included,
                    'reason'     => $included ? null : $q->dropReason(),
                    'lines'      => CartItem::where('cart_id', $q->cart_id)->where('store_id', $q->store_id)->orderBy('id')->get()
                        ->map(fn (CartItem $i) => [
                            'title'            => CartItem::cleanTitle((string) $i->title),
                            'variants'         => $i->variants,
                            'quantity'         => $i->quantity,
                            'unit_price_cents' => $i->price !== null ? (int) round($i->price * 100) : null,
                            'state'            => $i->sync_status ?: 'ok',
                            // the line's photo (the picked colour's when the store gave one): the invoice card shows it
                            'image_url'        => $i->image_url,
                        ])->all(),
                    'merchandise_cents' => $q->merchandise_cents,
                    'discounts_cents'   => $q->discounts_cents,
                    'shipping_cents'    => $q->shipping_cents,
                    'tax_cents'         => $q->tax_cents,
                    'fees_cents'        => $q->fees_cents,
                    'total_cents'       => $q->total_cents,
                ];
            })->values()->all(),
            'stores_total_cents'  => $totals['stores_cents'],
            'commission_percent'  => $totals['fee_percent'],
            'commission_cents'    => $totals['fee_cents'],
            'total_cents'         => $totals['total_cents'],
            'invoice_total_cents' => $pr->stripe_invoice_id && $pr->total_usd !== null ? (int) round($pr->total_usd * 100) : null,
            'invoiced'            => (bool) $pr->stripe_invoice_id,
        ];
    }

    /**
     * The lines that are not billed: a partial quote's unavailable lines, and every line of a store whose total could
     * not be verified (it drops out of the invoice). Their purchase request items are marked unavailable.
     */
    private static function markUnavailableItems(PurchaseRequest $pr, $quotes): void
    {
        $unbilledStores = $quotes->filter(fn (StoreQuote $q) => ! in_array($q->status, StoreQuote::BILLABLE, true))->pluck('store_id')->all();
        $urls = CartItem::whereIn('cart_id', $quotes->pluck('cart_id')->unique())
            ->where(fn ($q) => $q->where('sync_status', 'unavailable')->orWhereIn('store_id', $unbilledStores))
            ->pluck('product_url')
            ->all();
        if ($urls !== []) {
            PurchaseRequestItem::where('purchase_request_id', $pr->id)->whereIn('product_url', $urls)
                ->update(['stock_status' => PurchaseRequestItem::STOCK_UNAVAILABLE]);
        }
    }
}
