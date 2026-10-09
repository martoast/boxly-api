<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two warehouses, one scan flow (Alex, 2026-10-09): packages are scanned when they reach the San Diego
 * warehouse (Mauricio) and again when they reach Tijuana (Erick). A warehouse employee has a
 * warehouse_location; every label scan records where it was scanned. Existing rows are San Diego
 * (the only warehouse scanning until now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('warehouse_location', 20)->nullable()->after('team');
        });
        Schema::table('label_scans', function (Blueprint $table) {
            $table->string('location', 20)->default('san_diego')->after('batch')->index();
        });

        // Erick runs the Tijuana warehouse; everyone else (Mauricio) stays San Diego (null = San Diego).
        DB::table('users')
            ->where('role', 'employee')->where('team', 'warehouse')
            ->where('name', 'like', 'Erick%')
            ->update(['warehouse_location' => 'tijuana']);
    }

    public function down(): void
    {
        Schema::table('label_scans', function (Blueprint $table) {
            $table->dropIndex(['location']);
            $table->dropColumn('location');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('warehouse_location');
        });
    }
};
