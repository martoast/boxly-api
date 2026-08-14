<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the courier (Jesús / Paco of Estafeta) actually charged us to move THIS
 * box, in MXN, recorded against the box itself.
 *
 * Courier spend is 69% of all expenses ($1.33M of $1.94M all-time) and has only
 * ever existed as per-run lump sums in business_expenses — "JESUS $8,650" with
 * no way back to a customer's box. So margin per box size has never been
 * measurable: loss-making-boxes-2026.csv had to model freight at 129 MXN/kg,
 * and its `guia` column is empty on all 108 rows because nothing linked the two.
 *
 * Captured here rather than allocated from the lump sum: the admin already
 * types the guía and weight for each box after it's packed, and the courier
 * prices per box. A number typed from the invoice is worth more than any
 * split we could infer from ship dates.
 *
 * NOTE for reporting: this is the SAME money as the shipping business_expense
 * rows, not additional spend. Sum it for per-size margin; never add it to the
 * expense total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->decimal('shipping_cost', 10, 2)->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->dropColumn('shipping_cost');
        });
    }
};
