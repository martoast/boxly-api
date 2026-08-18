<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\Log;
use Stripe\Price;

/**
 * The $10-per-store reservation charge for an in-person Las Americas visit.
 *
 * Step 1 of the two-step in-person flow: the customer pays a flat fee per
 * store before we commit to the trip. Step 2 — what was actually spent at
 * the stores plus the Boxly commission — is a separate Stripe invoice minted
 * later by AdminPurchaseRequestController::createQuote.
 *
 * The fee lives on the shopping Stripe account as a real product + price so
 * the shopping team can see and reconcile it in the dashboard like any other
 * line they sell. It is provisioned on first use rather than configured by
 * hand: production and local point at different Stripe accounts, and a manual
 * dashboard step is a step that eventually gets skipped.
 */
class InPersonDeposit
{
    /**
     * Stable handle for the price. Lookup keys are unique per Stripe account,
     * which is what makes find-or-create safe to run on every call.
     */
    public const PRICE_LOOKUP_KEY = 'boxly_in_person_per_store_fee_usd';

    /** Resolved price, cached for the life of the process. */
    private static ?Price $memoizedPrice = null;

    /**
     * The per-store fee, in USD, read live from Stripe.
     *
     * Falls back to config only if Stripe is unreachable — a booking is worth
     * more than a perfectly-sourced price, and the two are seeded to agree.
     */
    public static function perStoreFeeUsd(): float
    {
        try {
            return round(self::price()->unit_amount / 100, 2);
        } catch (\Exception $e) {
            Log::error('Could not read the in-person per-store fee from Stripe, falling back to config', [
                'error' => $e->getMessage(),
            ]);

            return (float) config('services.in_person.per_store_fee_usd', 10);
        }
    }

    /**
     * The Stripe Price for one store visit, creating the product and price
     * the first time this account needs them.
     */
    public static function price(): Price
    {
        // Cached: storeInPerson() needs the amount to stamp on
        // the PR and then again to build the link, and those two must be the
        // same price or the customer is quoted one number and billed another.
        if (self::$memoizedPrice !== null) {
            return self::$memoizedPrice;
        }

        $stripe = StripeAccount::shopping();

        $existing = $stripe->prices->all([
            'lookup_keys' => [self::PRICE_LOOKUP_KEY],
            'active'      => true,
            'limit'       => 1,
        ]);

        if (! empty($existing->data)) {
            $price = $existing->data[0];

            // The customer's own self-serve checkout still builds this charge
            // from config with an ad-hoc price, so the two can drift if someone
            // edits the price in the Stripe dashboard. We don't overwrite their
            // edit — Stripe wins — but we make the divergence loud.
            $configured = (int) round((float) config('services.in_person.per_store_fee_usd', 10) * 100);
            if ($price->unit_amount !== $configured) {
                Log::warning('In-person per-store fee differs between Stripe and config', [
                    'stripe_cents' => $price->unit_amount,
                    'config_cents' => $configured,
                    'price_id'     => $price->id,
                ]);
            }

            return self::$memoizedPrice = $price;
        }

        $product = $stripe->products->create([
            'name'        => 'Boxly — Compra en persona (por tienda)',
            'description' => 'Reserva de visita a una tienda en Las Américas. Se cobra por cada tienda que el equipo Boxly visita.',
            'metadata'    => ['boxly_kind' => 'in_person_per_store_fee'],
        ]);

        $price = $stripe->prices->create([
            'product'     => $product->id,
            'currency'    => 'usd',
            'unit_amount' => (int) round((float) config('services.in_person.per_store_fee_usd', 10) * 100),
            'lookup_key'  => self::PRICE_LOOKUP_KEY,
            'metadata'    => ['boxly_kind' => 'in_person_per_store_fee'],
        ]);

        Log::info('Provisioned the in-person per-store fee product on the shopping account', [
            'product_id' => $product->id,
            'price_id'   => $price->id,
        ]);

        return self::$memoizedPrice = $price;
    }

    /**
     * Mint a shareable Payment Link for a PR's deposit and persist it.
     *
     * A Payment Link rather than a Checkout Session because this URL gets
     * pasted into WhatsApp: sessions expire in roughly a day, links don't.
     * `completed_sessions.limit = 1` keeps it single-use so a forwarded link
     * can't be paid twice.
     *
     * The metadata mirrors what the self-serve Checkout Session sets, because
     * Stripe copies a Payment Link's metadata onto the sessions it creates and
     * StripeWebhookController dispatches on exactly these keys.
     */
    public static function createPaymentLink(PurchaseRequest $purchaseRequest): string
    {
        $storeCount = max(1, (int) $purchaseRequest->in_person_store_count);

        $metadata = [
            'type'                => 'in_person_deposit',
            'purchase_request_id' => (string) $purchaseRequest->id,
            'request_number'      => (string) $purchaseRequest->request_number,
        ];

        $link = StripeAccount::shopping()->paymentLinks->create([
            'line_items'   => [[
                'price'    => self::price()->id,
                'quantity' => $storeCount,
            ]],
            'metadata'     => $metadata,
            'restrictions' => ['completed_sessions' => ['limit' => 1]],
            // Mirrored onto the PaymentIntent so the charge is identifiable in
            // the Stripe dashboard without opening the session.
            'payment_intent_data' => ['metadata' => $metadata],
            'after_completion'    => [
                'type'     => 'redirect',
                'redirect' => [
                    'url' => config('app.frontend_url') . '/in-person/success?ref=' . urlencode($purchaseRequest->request_number),
                ],
            ],
        ]);

        $purchaseRequest->update([
            'deposit_payment_link'    => $link->url,
            'deposit_payment_link_id' => $link->id,
        ]);

        return $link->url;
    }

    /**
     * Retire a link once its deposit has cleared. Belt and braces on top of
     * the single-use restriction; never fatal, since the payment already
     * landed and the PR has already moved on.
     */
    public static function deactivatePaymentLink(PurchaseRequest $purchaseRequest): void
    {
        if (! $purchaseRequest->deposit_payment_link_id) {
            return;
        }

        try {
            StripeAccount::shopping()->paymentLinks->update(
                $purchaseRequest->deposit_payment_link_id,
                ['active' => false],
            );
        } catch (\Exception $e) {
            Log::warning('Could not deactivate a paid in-person deposit link', [
                'purchase_request_id' => $purchaseRequest->id,
                'payment_link_id'     => $purchaseRequest->deposit_payment_link_id,
                'error'               => $e->getMessage(),
            ]);
        }
    }
}
