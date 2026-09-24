<?php

namespace App\Services;

use App\Models\User;

/**
 * The internal-test gate for the live-carts product (cart sync, checkout
 * quotes, automatic invoices): only allowlisted testers get it, so no real
 * customer sees it while it is being evaluated. BOXLY_BETA_EMAILS is a
 * comma-separated list; "*" opens it to everyone (never in production).
 */
class BoxlyBeta
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $list = (array) config('services.boxly_beta.emails', []);
        if (in_array('*', $list, true)) {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $user->email)), $list, true);
    }
}
