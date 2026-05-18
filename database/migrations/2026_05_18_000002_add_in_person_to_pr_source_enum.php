<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Third PR source: customer schedules a Boxly team member to physically
 * shop at Las Americas Outlets on their behalf. Source enum is on MySQL
 * so we extend it with a raw ALTER (Schema::enum can't append).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE purchase_requests MODIFY COLUMN source "
            . "ENUM('store', 'assisted', 'in_person') NOT NULL DEFAULT 'assisted'"
        );
    }

    public function down(): void
    {
        // Defensive: if any in_person rows exist we'd lose them on a strict
        // enum revert. Flip them to 'assisted' first so the rollback is safe.
        DB::table('purchase_requests')
            ->where('source', 'in_person')
            ->update(['source' => 'assisted']);

        DB::statement(
            "ALTER TABLE purchase_requests MODIFY COLUMN source "
            . "ENUM('store', 'assisted') NOT NULL DEFAULT 'assisted'"
        );
    }
};
