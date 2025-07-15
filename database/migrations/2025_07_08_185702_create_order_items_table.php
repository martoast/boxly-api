<?php
// database/migrations/2025_07_08_185702_create_order_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            
            // Product information
            $table->string('product_url', 1000); // URL of the product they purchased
            $table->string('product_name');
            $table->string('retailer')->nullable(); // Amazon, eBay, etc (extracted from URL)
            
            // Purchase details
            $table->integer('quantity')->default(1);
            $table->decimal('declared_value', 10, 2)->nullable()->comment('Price paid per unit in USD - optional');
            
            // Tracking information - multiple options for flexibility
            $table->string('tracking_number')->nullable()->comment('Retailer tracking number');
            $table->string('tracking_url', 1000)->nullable()->comment('Full tracking URL if provided');
            $table->enum('carrier', [
                'ups',
                'fedex',
                'usps',
                'amazon',
                'dhl',
                'other',
                'unknown'
            ])->nullable()->comment('Detected or selected carrier');
            
            // Package arrival tracking
            $table->boolean('arrived')->default(false);
            $table->timestamp('arrived_at')->nullable();
            
            // Physical measurements (filled by admin when package arrives)
            $table->decimal('weight', 8, 2)->nullable()->comment('Weight in kg');
            $table->json('dimensions')->nullable()->comment('length, width, height in cm');
            
            $table->timestamps();
            
            // Indexes
            $table->index('order_id');
            $table->index(['order_id', 'arrived']);
            $table->index('tracking_number');
            $table->index('arrived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};