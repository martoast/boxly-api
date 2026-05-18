<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit tracking for in-person PRs.
 *
 *  - deposit_amount_usd: locked at session creation ($10 × store count).
 *    Kept in USD even though Stripe's adaptive pricing may charge the
 *    customer in MXN at checkout — settlement happens in USD.
 *  - deposit_checkout_session_id: Stripe Checkout Session ID so the
 *    webhook handler can find the PR by session id if metadata is lost.
 *    Indexed for that lookup.
 *  - deposit_paid_at: set by the webhook when checkout.session.completed
 *    fires. Presence means "deposit cleared, trip is confirmed".
 *
 * All nullable so non-in-person PRs (store / assisted) are untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('deposit_amount_usd', 10, 2)->nullable()->after('store_categories');
            $table->string('deposit_checkout_session_id')->nullable()->after('deposit_amount_usd');
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_checkout_session_id');

            $table->index('deposit_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropIndex(['deposit_checkout_session_id']);
            $table->dropColumn(['deposit_amount_usd', 'deposit_checkout_session_id', 'deposit_paid_at']);
        });
    }
};
