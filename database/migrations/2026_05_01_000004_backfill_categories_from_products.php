<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Move every distinct value in products.category into the new categories
 * table, link each product through the pivot, then drop the old column.
 *
 * Done in one transaction so a failure leaves nothing half-migrated.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $existing = DB::table('products')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->pluck('category')
                ->unique()
                ->values();

            $now = now();

            // Create one Category row per unique string. Slug from kebab-cased name.
            $categoryIdsByName = [];
            foreach ($existing as $name) {
                $slug = Str::slug($name);
                if (empty($slug)) continue;

                // Avoid slug collisions across different name casings.
                $existingId = DB::table('categories')->where('slug', $slug)->value('id');
                if ($existingId) {
                    $categoryIdsByName[$name] = $existingId;
                    continue;
                }

                $id = DB::table('categories')->insertGetId([
                    'name'       => $name,
                    'slug'       => $slug,
                    'is_active'  => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryIdsByName[$name] = $id;
            }

            // Link each product to its category via the pivot.
            $products = DB::table('products')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->get(['id', 'category']);

            $pivotRows = [];
            foreach ($products as $product) {
                $catId = $categoryIdsByName[$product->category] ?? null;
                if (! $catId) continue;

                $pivotRows[] = [
                    'category_id' => $catId,
                    'product_id'  => $product->id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            if (! empty($pivotRows)) {
                DB::table('category_product')->insert($pivotRows);
            }
        });

        // Drop the old string column once the data is moved.
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        // Recreate the column. Data loss is intentional on rollback — the
        // categories table is the canonical source after this migration.
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable()->after('status');
        });
    }
};
