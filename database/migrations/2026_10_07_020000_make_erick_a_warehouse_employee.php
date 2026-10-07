<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alex 2026-10-07: Erick's existing account becomes a warehouse employee (same as Mauricio) to test the
 * employee side. The previous migration used a mistyped email (ericmarto17@…), which matches no account.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'erickmartos17@gmail.com')
            ->update(['role' => 'employee', 'team' => 'warehouse']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'erickmartos17@gmail.com')
            ->update(['role' => 'customer', 'team' => null]);
    }
};
