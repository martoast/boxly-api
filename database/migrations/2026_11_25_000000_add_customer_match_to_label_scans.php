<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label scans matched to a customer (2026-11-25, for the 5 PM arrival WhatsApp): user_id = the ONE customer the label's
 * name confidently names (or the one a person assigned), match_status = matched | ambiguous | none | staff | manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_scans', function (Blueprint $table) {
            if (! Schema::hasColumn('label_scans', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('suite')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('label_scans', 'match_status')) {
                $table->string('match_status', 16)->nullable()->after('user_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('label_scans', function (Blueprint $table) {
            if (Schema::hasColumn('label_scans', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            if (Schema::hasColumn('label_scans', 'match_status')) {
                $table->dropColumn('match_status');
            }
        });
    }
};
