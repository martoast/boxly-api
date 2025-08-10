<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal info
            $table->string('phone')->nullable()->after('email');
            $table->string('preferred_language', 5)->default('es')->after('phone');
            
            // Mexican address format (for saved addresses)
            $table->string('street')->nullable();
            $table->string('exterior_number')->nullable();
            $table->string('interior_number')->nullable();
            $table->string('colonia')->nullable();
            $table->string('municipio')->nullable();
            $table->string('estado')->nullable();
            $table->string('postal_code')->nullable();
            
            // OAuth support
            $table->string('provider')->nullable()->comment('google, facebook, null');
            
            // Role and user type
            $table->enum('role', ['customer', 'admin'])->default('customer');
            $table->enum('user_type', ['expat', 'business', 'shopper'])->nullable();
            
            // Registration tracking - FIXED: Using JSON instead of TEXT
            $table->json('registration_source')->nullable()
                ->comment('JSON tracking data: UTM params, landing page, campaign info');
            
            // Stripe customer fields (from Cashier)
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            
            // Indexes
            $table->index('user_type');
            $table->index(['user_type', 'created_at']);
            $table->index('role');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['user_type', 'created_at']);
            $table->dropIndex(['user_type']);
            $table->dropIndex(['role']);
            $table->dropIndex(['provider']);
            $table->dropIndex(['stripe_id']);
            
            // Drop columns
            $table->dropColumn([
                'phone',
                'preferred_language',
                'street',
                'exterior_number',
                'interior_number',
                'colonia',
                'municipio',
                'estado',
                'postal_code',
                'provider',
                'role',
                'user_type',
                'registration_source',
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at'
            ]);
        });
    }
};