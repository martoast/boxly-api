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
        Schema::table('order_items', function (Blueprint $table) {
            // Proof of purchase file fields
            $table->string('proof_of_purchase_path', 1000)->nullable()->after('carrier');
            $table->string('proof_of_purchase_filename')->nullable()->after('proof_of_purchase_path');
            $table->string('proof_of_purchase_mime_type')->nullable()->after('proof_of_purchase_filename');
            $table->integer('proof_of_purchase_size')->nullable()->after('proof_of_purchase_mime_type');
            $table->string('proof_of_purchase_url', 1000)->nullable()->after('proof_of_purchase_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'proof_of_purchase_path',
                'proof_of_purchase_filename',
                'proof_of_purchase_mime_type',
                'proof_of_purchase_size',
                'proof_of_purchase_url'
            ]);
        });
    }
};