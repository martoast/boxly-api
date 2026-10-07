<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One package that arrived at the warehouse, read off a photo of its shipping label.
 *
 * The warehouse photographs every label as boxes come in, then uploads the whole
 * set at once (60+). The phone decodes the barcodes (exact tracking number) and a
 * vision model reads the recipient's name; each package becomes one row here.
 *
 * Deliberately standalone — not tied to an order. It records "this box, for this
 * name, with this tracking number, arrived", so we can tell the client on WhatsApp
 * and they check the tracking number themselves. One photo can hold two labels,
 * so two rows may share one image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_scans', function (Blueprint $table) {
            $table->id();

            // One upload session (all photos picked together share it).
            $table->string('batch', 40)->nullable()->index();

            $table->string('tracking_number')->nullable()->index();
            $table->string('carrier', 40)->nullable();
            // Other tracking numbers on the same label, e.g. the USPS leg of UPS SurePost.
            $table->json('other_tracking')->nullable();

            $table->string('recipient_name')->nullable();
            $table->string('suite', 40)->nullable();
            $table->string('ship_from')->nullable();
            $table->json('store_order_numbers')->nullable();

            // Everything decoded from the barcodes, and what the model read as the
            // printed tracking number — kept so a wrong row can be diagnosed.
            $table->json('barcodes')->nullable();
            $table->string('model_tracking_read')->nullable();
            $table->string('confidence', 10)->nullable();

            // No barcode tracking number, no name, or a low-confidence read.
            $table->boolean('needs_check')->default(false);

            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_scans');
    }
};
