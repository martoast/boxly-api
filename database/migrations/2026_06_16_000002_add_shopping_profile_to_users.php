<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Learned shopping preferences the AI assistant maintains over time
            // (sizes, brands, categories, budget, style notes). Cross-chat memory.
            $table->json('shopping_profile')->nullable()->after('preferred_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shopping_profile');
        });
    }
};
