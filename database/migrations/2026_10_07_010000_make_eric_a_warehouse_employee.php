<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alex 2026-10-07: Eric's personal account becomes a warehouse employee (same as Mauricio) so the
 * employee side — label scans, packages, drop-offs — can be tested as Mauricio sees it. There is no
 * admin screen for roles; this is the one-off. No account with that email → nothing happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'ericmarto17@gmail.com')
            ->update(['role' => 'employee', 'team' => 'warehouse']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'ericmarto17@gmail.com')
            ->update(['role' => 'customer', 'team' => null]);
    }
};
