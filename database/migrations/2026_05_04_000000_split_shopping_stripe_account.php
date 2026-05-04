<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split the shopping/PR Stripe flow onto its own Stripe account.
 *
 *  • users.stripe_shopping_id — customer ID in the shopping account.
 *    Lazily created the first time we invoice the user from that account.
 *
 *  • purchase_requests.stripe_account — which Stripe account this PR's
 *    invoice lives on. Null/'main' = legacy invoice from the main account
 *    (created before the split). 'shopping' = new invoice from the
 *    shopping account. Read by the void/retrieve dispatcher to pick the
 *    right Stripe client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_shopping_id')->nullable()->after('stripe_id')->index();
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('stripe_account')->nullable()->after('stripe_invoice_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_shopping_id']);
            $table->dropColumn('stripe_shopping_id');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropIndex(['stripe_account']);
            $table->dropColumn('stripe_account');
        });
    }
};
