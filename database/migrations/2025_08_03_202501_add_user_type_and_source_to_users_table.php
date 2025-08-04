<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // User type for segmentation
            $table->enum('user_type', ['expat', 'business', 'shopper'])->nullable()->after('role');
            
            // Track registration source with JSON data for comprehensive tracking
            // Using TEXT to handle potentially long JSON strings with multiple UTM parameters
            $table->text('registration_source')->nullable()->after('user_type')
                ->comment('JSON tracking data: UTM params, landing page, campaign info');
            
            // Add indexes for better query performance
            $table->index('user_type');
            $table->index(['user_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['user_type', 'created_at']);
            $table->dropIndex(['user_type']);
            
            $table->dropColumn([
                'user_type',
                'registration_source'
            ]);
        });
    }
};