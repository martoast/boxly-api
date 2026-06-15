<?php

namespace App\Services;

use Laravel\Cashier\Cashier;
use Stripe\StripeClient;

/**
 * Resolves a configured Stripe SDK client for one of our two Stripe accounts.
 *
 *  • main()      — the original account (boxes / shipping / Cashier-managed
 *                  customers via User->stripe_id). Identical to Cashier::stripe()
 *                  so call sites stay readable.
 *  • shopping()  — the second account, used only for Purchase Request
 *                  invoices + in-person deposits. Customers there live in
 *                  User->stripe_shopping_id, created on demand.
 *
 * Use this helper in any code path that needs to talk to Stripe so it's
 * obvious from the call site which account it touches.
 */
class StripeAccount
{
    public static function main(): StripeClient
    {
        return Cashier::stripe();
    }

    public static function shopping(): StripeClient
    {
        $secret = config('services.stripe_shopping.secret');
        if (! $secret) {
            throw new \RuntimeException('STRIPE_SHOPPING_SECRET is not configured');
        }
        return new StripeClient($secret);
    }
}
