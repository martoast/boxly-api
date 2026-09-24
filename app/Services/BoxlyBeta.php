<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Boxly Lab: the live-carts product (cart, store sync, checkout quotes,
 * automatic invoices) for internal testing in production. An account is in the
 * Lab once it opts in on the unlisted /app/lab page ("Entrar al Lab", which
 * sets users.boxly_lab_joined_at). BOXLY_BETA_EMAILS (optional) adds accounts
 * without the opt-in. Everyone else never sees any of it.
 */
class BoxlyBeta
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->getAttribute('boxly_lab_joined_at') !== null) {
            return true;
        }
        $list = (array) config('services.boxly_beta.emails', []);

        return in_array('*', $list, true) || in_array(mb_strtolower(trim((string) $user->email)), $list, true);
    }

    /** Opt the account into the Lab (idempotent). */
    public static function join(User $user): void
    {
        if ($user->getAttribute('boxly_lab_joined_at') === null && Schema::hasColumn('users', 'boxly_lab_joined_at')) {
            $user->forceFill(['boxly_lab_joined_at' => now()])->save();
        }
    }
}
