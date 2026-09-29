<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Simultaneous shoppers (2026-09-28): a live session waiting for a free engine slot is a pending row with no engine
// session yet and a queued_at — the LiveQueue starts it, in order, when a slot frees.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('live_shopping_sessions', 'queued_at')) {
            Schema::table('live_shopping_sessions', function (Blueprint $table) {
                $table->timestamp('queued_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('live_shopping_sessions', 'queued_at')) {
            Schema::table('live_shopping_sessions', function (Blueprint $table) {
                $table->dropColumn('queued_at');
            });
        }
    }
};
