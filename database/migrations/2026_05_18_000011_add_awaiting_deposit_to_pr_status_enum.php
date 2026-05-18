<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * In-person PRs go behind a paywall: customer submits the form, a Stripe
 * Checkout Session is created for the $10/store deposit, and the PR sits
 * in `awaiting_deposit` until the checkout webhook lands. Only then does
 * it flip to `pending_review` and become a "real" booking the admin sees.
 * Filters out tire-kickers who never intended to pay.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE purchase_requests MODIFY COLUMN status "
            . "ENUM('pending_review', 'quoted', 'paid', 'purchased', 'rejected', 'cancelled', 'awaiting_deposit') "
            . "NOT NULL DEFAULT 'pending_review'"
        );
    }

    public function down(): void
    {
        // Roll-back safety: any awaiting_deposit rows get flipped to cancelled
        // so the strict enum revert doesn't blow up.
        DB::table('purchase_requests')
            ->where('status', 'awaiting_deposit')
            ->update(['status' => 'cancelled']);

        DB::statement(
            "ALTER TABLE purchase_requests MODIFY COLUMN status "
            . "ENUM('pending_review', 'quoted', 'paid', 'purchased', 'rejected', 'cancelled') "
            . "NOT NULL DEFAULT 'pending_review'"
        );
    }
};
