<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag a store as available for in-person shopping at Las Americas. The
 * in-person flow's store picker queries the `stores` table filtered on
 * this flag — keeps the existing Boxly Store curation path untouched
 * while letting us seed mall-only brands (Coach, Nike outlet, etc.)
 * into the same table without polluting the storefront.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('is_in_person_available')->default(false)->after('show_on_landing');
            $table->index('is_in_person_available');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['is_in_person_available']);
            $table->dropColumn('is_in_person_available');
        });
    }
};
