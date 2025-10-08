<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, update existing records to the new value to avoid data integrity issues.
        DB::table('orders')->where('status', 'quote_sent')->update(['status' => 'awaiting_payment']);

        // Then, modify the column definition.
        // Using a raw statement because Doctrine (used by Laravel) doesn't fully support ENUM modifications.
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('collecting','awaiting_packages','packages_complete','processing','awaiting_payment','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'collecting'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, update records back to the old value.
        DB::table('orders')->where('status', 'awaiting_payment')->update(['status' => 'quote_sent']);

        // Then, revert the column definition to its original state.
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('collecting','awaiting_packages','packages_complete','processing','quote_sent','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'collecting'");
    }
};