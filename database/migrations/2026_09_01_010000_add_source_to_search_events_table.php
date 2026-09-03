<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segment search events by where they came from.
 *
 * NULL = every existing legacy row, semantics unchanged.
 * 'live_engine' = a terminal live-shopping session result set.
 *
 * Nullable, no default, no backfill — so old code runs unchanged against the new
 * schema and the deploy window is safe in either order. The admin aggregates add
 * whereNull('source') (guarded by Schema::hasColumn) so the historical series is
 * identical before AND after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
