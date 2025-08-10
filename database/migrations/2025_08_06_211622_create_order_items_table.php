<?php

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
            $table->string('product_url', 1000)->nullable();
            $table->string('product_name');
            $table->string('product_image_url', 1000)->nullable();
            $table->string('retailer')->nullable();
            
            // Purchase details
            $table->integer('quantity')->default(1);
            $table->decimal('declared_value', 10, 2)->nullable();
            
            // Tracking
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 1000)->nullable();
            $table->enum('carrier', [
                'ups',
                'fedex',
                'usps',
                'amazon',
                'dhl',
                'ontrac',
                'lasership',
                'other',
                'unknown'
            ])->nullable();
            
            // Arrival tracking
            $table->boolean('arrived')->default(false);
            $table->timestamp('arrived_at')->nullable();
            
            // Measurements
            $table->decimal('weight', 8, 2)->nullable();
            $table->json('dimensions')->nullable();
            
            // Proof of purchase
            $table->string('proof_of_purchase_path', 1000)->nullable();
            $table->string('proof_of_purchase_filename')->nullable();
            $table->string('proof_of_purchase_mime_type')->nullable();
            $table->integer('proof_of_purchase_size')->nullable();
            $table->string('proof_of_purchase_url', 1000)->nullable();
            
            // Additional notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('order_id');
            $table->index(['order_id', 'arrived']);
            $table->index('tracking_number');
            $table->index('arrived_at');
            $table->index('arrived');
            $table->index('weight');
            $table->index('retailer');
            $table->index('carrier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};