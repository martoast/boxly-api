<?php

namespace App\Services;

use App\Mail\PurchaseRequestQuoteSent;
use App\Models\PurchaseRequest;
use App\Models\StoreQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * C5 — the automatic invoice from verified store quotes (D9, decided
 * 2026-09-24). Same Stripe account, metadata and invoice.paid path as the
 * shopping team's manual quote (AdminPurchaseRequestController::createQuote),
 * but one line per store — that store's own verified checkout total to the
 * warehouse (merchandise, shipping, tax, fees) — plus Boxly's commission.
 */
class StoreQuoteInvoice
{
    public function send(PurchaseRequest $pr, Collection $quotes): void
    {
        $billable = $quotes->filter(fn (StoreQuote $q) => in_array($q->status, StoreQuote::BILLABLE, true))->values();
        $storesCents = (int) $billable->sum('total_cents');
        $feePercent = (float) config('services.commission.default_percent', 15);
        $feeCents = (int) round($storesCents * $feePercent / 100);
        $totalCents = $storesCents + $feeCents;

        $user = $pr->user;
        $customer = $user->stripeShoppingCustomerId();
        $stripe = StripeAccount::shopping();

        $invoice = $stripe->invoices->create([
            'customer'          => $customer,
            'currency'          => 'usd',
            'collection_method' => 'send_invoice',
            'days_until_due'    => 3,
            'description'       => "Boxly — Solicitud de Compra {$pr->request_number} (totales verificados en cada tienda)",
            'metadata' => [
                'type'                => 'purchase_request_invoice',
                'purchase_request_id' => $pr->id,
                'request_number'      => $pr->request_number,
                'source'              => (string) $pr->source,
                'auto_quote'          => '1',
            ],
            'auto_advance' => false,
        ]);

        $line = function (string $description, int $cents) use ($stripe, $customer, $invoice) {
            if ($cents <= 0) {
                return;
            }
            $stripe->invoiceItems->create([
                'customer'    => $customer,
                'invoice'     => $invoice->id,
                'amount'      => $cents,
                'currency'    => 'usd',
                'description' => mb_substr($description, 0, 250),
            ]);
        };

        foreach ($billable as $q) {
            $name = $q->store_name ?: $q->store_id;
            $line(
                "{$name}: productos, envío e impuestos a nuestra bodega en San Diego (total de la tienda"
                . ($q->estimated ? ', impuestos estimados por la tienda' : '') . ')',
                (int) $q->total_cents,
            );
        }
        $feeLabel = rtrim(rtrim(number_format($feePercent, 1, '.', ''), '0'), '.');
        $line("Comisión Boxly / Boxly commission ({$feeLabel}%)", $feeCents);

        $stripe->invoices->finalizeInvoice($invoice->id);
        $sent = $stripe->invoices->sendInvoice($invoice->id);

        $usd = fn (int $cents) => round($cents / 100, 2);
        $storeCosts = [];
        foreach ($billable as $q) {
            $storeCosts[$q->store_id] = [
                'name'        => $q->store_name,
                'merchandise' => $q->merchandise_cents !== null ? $usd($q->merchandise_cents) : null,
                'discounts'   => $q->discounts_cents !== null ? $usd($q->discounts_cents) : null,
                'shipping'    => $q->shipping_cents !== null ? $usd($q->shipping_cents) : null,
                'tax'         => $q->tax_cents !== null ? $usd($q->tax_cents) : null,
                'fees'        => $q->fees_cents !== null ? $usd($q->fees_cents) : null,
                'total'       => $usd((int) $q->total_cents),
                'estimated'   => $q->estimated,
                'verified_at' => optional($q->observed_at)->toIso8601String(),
            ];
        }

        $pr->update([
            'items_total'       => $usd($storesCents),
            'shipping_cost'     => $usd((int) $billable->sum('shipping_cents')),
            'sales_tax'         => $usd((int) $billable->sum('tax_cents')),
            'store_costs'       => $storeCosts,
            'processing_fee'    => $usd($feeCents),
            'total_amount'      => $usd($totalCents),
            'total_usd'         => $usd($totalCents),
            'fx_rate_used'      => null,
            'currency'          => 'usd',
            'payment_method'    => PurchaseRequest::PAYMENT_METHOD_STRIPE,
            'status'            => PurchaseRequest::STATUS_QUOTED,
            'stripe_invoice_id' => $invoice->id,
            'stripe_account'    => PurchaseRequest::STRIPE_ACCOUNT_SHOPPING,
            'payment_link'      => $sent->hosted_invoice_url,
            'quote_sent_at'     => now(),
        ]);

        try {
            Mail::to($user)->queue(new PurchaseRequestQuoteSent($pr));
        } catch (\Throwable $e) {
            Log::error('Failed to queue auto-quote email: ' . $e->getMessage());
        }
        Log::info('automatic invoice sent', ['purchase_request_id' => $pr->id, 'total_cents' => $totalCents, 'stores' => $billable->count()]);
    }
}
