<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Gender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot migration: products that were tagged with the legacy "Hombre"
 * or "Mujer" categories get their `gender_id` set to the corresponding
 * seeded Gender, and the legacy category is detached. Optionally deletes
 * the now-empty legacy categories afterward.
 *
 * Idempotent: safe to re-run. Products already mapped to the right gender
 * get detached again (no-op if already detached). Products with a
 * conflicting existing gender_id are skipped with a warning, never
 * overwritten.
 *
 * Run on prod:
 *   php artisan boxly:migrate-gender-categories                          # dry run
 *   php artisan boxly:migrate-gender-categories --apply                  # actually migrate
 *   php artisan boxly:migrate-gender-categories --apply --delete-categories
 */
class MigrateGenderCategories extends Command
{
    protected $signature = 'boxly:migrate-gender-categories
                            {--apply : Actually perform the migration (default is dry-run)}
                            {--delete-categories : After migrating, delete the now-empty Hombre and Mujer categories}';

    protected $description = 'Move products from legacy "Hombre"/"Mujer" categories to the Gender FK and detach the categories.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $deleteCategories = (bool) $this->option('delete-categories');

        if (! $apply) {
            $this->warn('DRY RUN — no changes will be made. Pass --apply to commit.');
            $this->newLine();
        }

        // Match legacy categories to the seeded genders by slug. If the
        // user named them differently, edit this array before running.
        $mappings = [
            'hombre' => 'Hombre',
            'mujer'  => 'Mujer',
        ];

        $touched = 0;
        $skipped = 0;
        $categoriesProcessed = [];

        foreach ($mappings as $slug => $label) {
            $gender = Gender::where('slug', $slug)->first();
            if (! $gender) {
                $this->error("Gender '{$label}' (slug={$slug}) not found. Did the create_genders migration run?");
                return self::FAILURE;
            }

            // Match by slug first (the canonical key Laravel auto-generates),
            // then fall back to a case-insensitive name match in case the
            // slug was hand-edited at some point.
            $category = Category::where('slug', $slug)->first()
                ?? Category::whereRaw('LOWER(name) = ?', [strtolower($label)])->first();
            if (! $category) {
                $this->line("· Category '{$label}' not found — skipping (already migrated or never existed).");
                continue;
            }

            $products = $category->products()->get();
            $this->info("→ {$label}: category #{$category->id} → gender #{$gender->id} ({$products->count()} product(s))");

            foreach ($products as $product) {
                if ($product->gender_id && $product->gender_id !== $gender->id) {
                    $this->warn("  · SKIP product #{$product->id} '{$product->name}' — already has different gender_id={$product->gender_id}");
                    $skipped++;
                    continue;
                }

                $this->line("  · product #{$product->id} '{$product->name}'");

                if ($apply) {
                    DB::transaction(function () use ($product, $category, $gender) {
                        $product->update(['gender_id' => $gender->id]);
                        $product->categories()->detach($category->id);
                    });
                }
                $touched++;
            }

            $categoriesProcessed[] = $category;
        }

        if ($deleteCategories) {
            $this->newLine();
            $this->info('Cleaning up legacy categories…');
            foreach ($categoriesProcessed as $category) {
                $remaining = $category->products()->count();
                if ($remaining > 0) {
                    $this->warn("· Cannot delete category '{$category->name}' — still has {$remaining} product(s) attached.");
                    continue;
                }
                if ($apply) {
                    $category->delete();
                    $this->line("· Deleted category '{$category->name}' (id={$category->id})");
                } else {
                    $this->line("· Would delete category '{$category->name}' (id={$category->id})");
                }
            }
        }

        $this->newLine();
        $this->info("Done. Touched: {$touched}, Skipped: {$skipped}.");
        if (! $apply) {
            $this->warn('Dry run — no changes were made. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
