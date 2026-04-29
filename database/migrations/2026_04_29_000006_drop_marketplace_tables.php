<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Boxly Store no longer maintains its own order ledger — store checkouts now create
 * a PurchaseRequest in `quoted` state and ride the existing assisted-purchase flow.
 * These tables are unused; drop them. (No down migration since the app no longer
 * references them.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('marketplace_orders');
    }

    public function down(): void
    {
        // intentionally no-op
    }
};
