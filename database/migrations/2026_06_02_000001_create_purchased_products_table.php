<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchased_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Source PR when auto-seeded from "Mark Purchased"; null for manual rows.
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('contact_phone')->nullable();
            $table->text('items')->nullable();
            $table->string('order_number')->nullable(); // store's actual order #, entered by Velonie
            $table->string('status')->default('pending'); // pending | delivered
            $table->date('order_date')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('purchase_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchased_products');
    }
};
