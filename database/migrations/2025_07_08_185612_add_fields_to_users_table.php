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
            
            // Mexican address format
            $table->string('street')->nullable();
            $table->string('exterior_number')->nullable();
            $table->string('interior_number')->nullable();
            $table->string('colonia')->nullable();
            $table->string('municipio')->nullable();
            $table->string('estado')->nullable();
            $table->string('postal_code')->nullable();
            
            // OAuth support
            $table->string('provider')->nullable()->comment('google, facebook, null');
            
            // Role
            $table->enum('role', ['customer', 'admin'])->default('customer');
            
            // Stripe customer fields (from Cashier)
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'street',
                'exterior_number',
                'interior_number',
                'colonia',
                'municipio',
                'estado',
                'postal_code',
                'provider',
                'role',
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at'
            ]);
        });
    }
};