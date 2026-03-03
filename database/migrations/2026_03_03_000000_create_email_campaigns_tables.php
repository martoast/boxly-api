<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->string('link_url')->nullable();
            $table->string('link_text')->nullable();
            $table->enum('audience', ['all', 'has_orders', 'no_orders', 'active_orders', 'expat', 'business', 'shopper']);
            $table->enum('status', ['draft', 'sending', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->unsignedInteger('batch_size')->default(50);
            $table->unsignedInteger('batch_delay_seconds')->default(60);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('email');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->string('tracking_token', 64)->unique();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('email_campaigns');
    }
};
