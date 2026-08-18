<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-person deposit ($10 × stores) gets its own link columns.
 *
 * `payment_link` is already taken by step 2 — the post-trip invoice for what
 * was actually spent plus commission — and that step overwrites it. The
 * deposit is a separate charge on the same PR, so it needs somewhere of its
 * own to live or minting the quote would erase the reservation link.
 *
 * `deposit_payment_link_id` is kept alongside the URL so the webhook can
 * deactivate the link once it's been paid.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('deposit_payment_link')->nullable()->after('deposit_checkout_session_id');
            $table->string('deposit_payment_link_id')->nullable()->after('deposit_payment_link');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['deposit_payment_link', 'deposit_payment_link_id']);
        });
    }
};
