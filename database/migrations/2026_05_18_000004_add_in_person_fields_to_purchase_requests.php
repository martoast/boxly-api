<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields only used when source=in_person, all nullable so existing PRs
 * (store + assisted) remain untouched.
 *
 * - shopping_trip_id: the scheduled visit the customer booked onto.
 * - customer_notes:    free-form notes the customer adds at submission
 *                      ("looking for size 10 sneakers, willing to pay extra
 *                       for Travis Scott colorway") — sibling to admin_notes.
 * - minimum_budget_usd: the customer's stated USD budget floor; admin uses
 *                      this as a signal of how much to actually spend at the
 *                      mall before quoting.
 * - in_person_store_count: snapshot of stores the customer chose at submission.
 *                      createQuote multiplies $10 × this to compute the
 *                      per-store service fee automatically. Stored on the PR
 *                      so admin can adjust if reality diverged from the
 *                      original plan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('shopping_trip_id')
                ->nullable()
                ->after('source')
                ->constrained('shopping_trips')
                ->nullOnDelete();

            $table->text('customer_notes')->nullable()->after('admin_notes');
            $table->decimal('minimum_budget_usd', 10, 2)->nullable()->after('customer_notes');
            $table->unsignedSmallInteger('in_person_store_count')->nullable()->after('minimum_budget_usd');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_trip_id');
            $table->dropColumn(['customer_notes', 'minimum_budget_usd', 'in_person_store_count']);
        });
    }
};
