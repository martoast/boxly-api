<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename dhl_waybill_number to guia_number
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('dhl_waybill_number', 'guia_number');
        });

        // 2. Add Deposit Tracking Columns
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('amount_paid');
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_amount');
            $table->string('deposit_invoice_id')->nullable()->after('stripe_invoice_id');
            $table->string('deposit_payment_link')->nullable()->after('payment_link');
        });

        // 3. Update Status Enum
        // Removed 'awaiting_deposit' as requested. 
        // The logic will use 'shipped' status combined with 'deposit_paid_at' null/not-null.
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'collecting',
            'awaiting_packages',
            'packages_complete',
            'processing',
            'shipped',
            'delivered',
            'awaiting_payment',
            'paid',
            'cancelled'
        ) NOT NULL DEFAULT 'collecting'");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('guia_number', 'dhl_waybill_number');
            $table->dropColumn([
                'deposit_amount',
                'deposit_paid_at',
                'deposit_invoice_id',
                'deposit_payment_link'
            ]);
        });

        // Revert status enum to previous state
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'collecting',
            'awaiting_packages',
            'packages_complete',
            'processing',
            'awaiting_payment',
            'paid',
            'shipped',
            'delivered',
            'cancelled'
        ) NOT NULL DEFAULT 'collecting'");
    }
};