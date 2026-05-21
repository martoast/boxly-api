<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Array of { path, url, filename, mime_type, size } — proof of
            // purchase now lives on the order, not the item, so customers can
            // upload multiple receipts (one per store) for the whole order.
            $table->json('proof_of_purchase_files')->nullable()->after('gia_url');
        });

        // Backfill: aggregate any existing per-item proofs up to their order.
        DB::table('order_items')
            ->whereNotNull('proof_of_purchase_url')
            ->orderBy('order_id')
            ->get([
                'order_id',
                'proof_of_purchase_path',
                'proof_of_purchase_filename',
                'proof_of_purchase_mime_type',
                'proof_of_purchase_size',
                'proof_of_purchase_url',
            ])
            ->groupBy('order_id')
            ->each(function ($items, $orderId) {
                $files = $items->map(fn ($i) => [
                    'path' => $i->proof_of_purchase_path,
                    'url' => $i->proof_of_purchase_url,
                    'filename' => $i->proof_of_purchase_filename,
                    'mime_type' => $i->proof_of_purchase_mime_type,
                    'size' => $i->proof_of_purchase_size,
                ])->values()->all();

                DB::table('orders')
                    ->where('id', $orderId)
                    ->update(['proof_of_purchase_files' => json_encode($files)]);
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('proof_of_purchase_files');
        });
    }
};
