<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a person cleared a scan's needs_check (2026-11-25, Suite's 5 PM arrival WhatsApp): a held row reaches /label-scans/arrivals
 * in the window its clearing falls in, not only the window it was uploaded in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_scans', function (Blueprint $table) {
            if (! Schema::hasColumn('label_scans', 'cleared_at')) {
                $table->timestamp('cleared_at')->nullable()->after('needs_check')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('label_scans', function (Blueprint $table) {
            if (Schema::hasColumn('label_scans', 'cleared_at')) {
                $table->dropColumn('cleared_at');
            }
        });
    }
};
