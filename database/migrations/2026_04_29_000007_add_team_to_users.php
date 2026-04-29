<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Sub-divide the `employee` role into teams. Mau (warehouse) handles inbound
 * packages + consolidation; Velonie (shopping) handles storefront CRUD and
 * fulfilling paid Purchase Requests by buying from US source stores.
 *
 * `team` is only meaningful when role=employee. Admins/customers stay null.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('team', ['warehouse', 'shopping'])->nullable()->after('role');
        });

        // Existing employees today are all warehouse (Mau).
        DB::table('users')->where('role', 'employee')->update(['team' => 'warehouse']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('team');
        });
    }
};
