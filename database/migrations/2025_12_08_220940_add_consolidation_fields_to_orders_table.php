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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('consolidation_invoice_id')->nullable()->after('deposit_payment_link');
            $table->string('consolidation_payment_link')->nullable()->after('consolidation_invoice_id');
            $table->timestamp('consolidation_paid_at')->nullable()->after('consolidation_payment_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['consolidation_invoice_id', 'consolidation_payment_link', 'consolidation_paid_at']);
        });
    }
};
