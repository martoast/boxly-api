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
        // 1. Affiliates table
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('affiliate_code')->unique();
            $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('commission_value', 10, 2)->default(10.00);
            $table->enum('status', ['pending', 'active', 'suspended'])->default('active');

            // Bank details for payouts
            $table->string('bank_beneficiary_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();

            // Earnings tracking
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('paid_earnings', 12, 2)->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('affiliate_code');
        });

        // 2. Affiliate referrals table - tracks which users were referred
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('referral_code_used');
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // A user can only be referred once
            $table->index('affiliate_id');
        });

        // 3. Affiliate conversions table - tracks paid orders
        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->onDelete('cascade');
            $table->foreignId('referral_id')->constrained('affiliate_referrals')->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->decimal('order_amount', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->timestamps();

            $table->unique('order_id'); // One conversion per order
            $table->index('affiliate_id');
            $table->index('status');
        });

        // 4. Affiliate payouts table - tracks manual bank transfers
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('paid_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index('affiliate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliates');
    }
};
