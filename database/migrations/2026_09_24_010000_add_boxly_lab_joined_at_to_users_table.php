<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boxly Lab opt-in: an account joins the Lab by pressing "Entrar al Lab" on the
 * unlisted /app/lab page. Null for everyone else, who never see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('boxly_lab_joined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('boxly_lab_joined_at');
        });
    }
};
