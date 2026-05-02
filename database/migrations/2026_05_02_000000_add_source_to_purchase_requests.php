<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish store-checkout PRs from admin-created assisted purchases.
 * Store sales need their own dashboard view; mixing them in the generic
 * PR list makes both noisier.
 *
 * Backfill: any existing PR whose admin_notes includes "Boxly Store
 * checkout" is the store-checkout marker StoreCheckoutController writes.
 * Everything else is an assisted (manual / admin-entered) request.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->enum('source', ['store', 'assisted'])
                ->default('assisted')
                ->after('status')
                ->index();
        });

        DB::table('purchase_requests')
            ->where('admin_notes', 'like', '%Boxly Store checkout%')
            ->update(['source' => 'store']);
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
