<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item stock verification for store-source PRs.
 *
 * When a customer checks out from the Boxly Store, the resulting PR lands in
 * `pending_review` and Velonie has to confirm each line is actually available
 * at the source retailer before sending the Stripe invoice. Each item carries
 * its own status independently — items marked unavailable stay on the PR (so
 * the customer sees them) but are excluded from the Stripe invoice line items.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->enum('stock_status', ['unverified', 'available', 'unavailable'])
                ->default('unverified')
                ->after('options');
            $table->timestamp('stock_checked_at')->nullable()->after('stock_status');
            $table->foreignId('stock_checked_by')
                ->nullable()
                ->after('stock_checked_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_checked_by');
            $table->dropColumn(['stock_status', 'stock_checked_at']);
        });
    }
};
