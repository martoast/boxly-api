<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Just track business expenses - revenue comes from orders!
        Schema::create('business_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->index(); // 'ads', 'software', 'office', 'po_box', 'misc'
            $table->string('subcategory', 50)->nullable(); // 'facebook_ads', 'google_ads', etc.
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('mxn');
            $table->date('expense_date')->index();
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable(); // Invoice #, receipt #
            $table->json('metadata')->nullable(); // Any extra data
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            // Composite indexes for fast filtering
            $table->index(['category', 'expense_date']);
        });

        // Track manual metrics that can't be calculated (conversations, signups from ads)
        Schema::create('monthly_manual_metrics', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month');
            $table->integer('total_conversations')->default(0); // From ad platforms
            $table->text('notes')->nullable();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_expenses');
        Schema::dropIfExists('monthly_manual_metrics');
    }
};