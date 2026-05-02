<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    /**
     * Admin list — all products including drafts/inactive, with pagination + filters.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:200',
            'status'   => 'nullable|in:draft,active,inactive,sold_out',
            'category' => 'nullable|string',
            'search'   => 'nullable|string|max:200',
        ]);

        $perPage = (int) $request->input('per_page', 20);

        $query = Product::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            // Default view hides soft-deleted products; users surface them
            // explicitly by filtering for status=inactive.
            $query->where('status', '!=', Product::STATUS_INACTIVE);
        }
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    public function show(Product $product)
    {
        $product->load(['variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id')]);
        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Replace all variants for a product with the given list. Used at creation time
     * (via the curator skill) and from the admin form to bulk-edit the variant matrix.
     *
     * Body: { variants: [{ size?, color?, shopify_variant_id?, price_cents?, display_order? }, ...] }
     */
    public function syncVariants(Request $request, Product $product)
    {
        $request->validate([
            'variants' => 'required|array',
            'variants.*.size'  => 'nullable|string|max:50',
            'variants.*.color' => 'nullable|string|max:100',
            'variants.*.shopify_variant_id' => 'nullable|string|max:100',
            'variants.*.price_cents' => 'nullable|integer|min:0',
            'variants.*.display_order' => 'nullable|integer|min:0',
        ]);

        $rows = $request->input('variants');

        try {
            DB::transaction(function () use ($product, $rows) {
                $product->variants()->delete();
                foreach ($rows as $i => $row) {
                    if (! ($row['size'] ?? null) && ! ($row['color'] ?? null)) continue;
                    $product->variants()->create([
                        'size'  => $row['size']  ?? null,
                        'color' => $row['color'] ?? null,
                        'shopify_variant_id' => $row['shopify_variant_id'] ?? null,
                        'price_cents' => $row['price_cents'] ?? null,
                        'display_order' => $row['display_order'] ?? $i,
                        'stock_check_status' => ProductVariant::STATUS_UNKNOWN,
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'data' => $product->fresh(['variants']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to sync variants', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudieron guardar las variantes: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function addVariant(Request $request, Product $product)
    {
        $validated = $request->validate([
            'size'  => 'nullable|string|max:50',
            'color' => 'nullable|string|max:100',
            'shopify_variant_id' => 'nullable|string|max:100',
            'price_cents' => 'nullable|integer|min:0',
            'display_order' => 'nullable|integer|min:0',
        ]);

        if (empty($validated['size']) && empty($validated['color'])) {
            return response()->json(['success' => false, 'message' => 'Debe especificar talla o color'], 422);
        }

        $variant = $product->variants()->create(array_merge($validated, [
            'stock_check_status' => ProductVariant::STATUS_UNKNOWN,
        ]));

        return response()->json([
            'success' => true,
            'data' => $variant,
        ], 201);
    }

    public function deleteVariant(Product $product, ProductVariant $variant)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['success' => false, 'message' => 'Variant not in this product'], 400);
        }
        $variant->delete();
        return response()->json(['success' => true]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'slug'            => 'nullable|string|max:255|unique:products,slug',
            'description'     => 'nullable|string',
            'sku'             => 'nullable|string|max:100',
            'source_url'      => 'nullable|url|max:1000',
            'requires_render' => 'nullable|boolean',
            'price_cents'     => 'required|integer|min:0',
            'cost_cents'      => 'nullable|integer|min:0',
            'markup_percent'  => 'nullable|numeric|min:0|max:999.99',
            'weight_kg'       => 'required|numeric|min:0.01|max:50',
            'length_cm'       => 'required|numeric|min:0.1|max:200',
            'width_cm'        => 'required|numeric|min:0.1|max:200',
            'height_cm'       => 'required|numeric|min:0.1|max:200',
            'stock'           => 'required|integer|min:0',
            'status'          => 'nullable|in:draft,active,inactive,sold_out',
            'available_until' => 'nullable|date|after:now',
            'category'        => 'nullable|string|max:100',
        ]);

        $validated['status'] = $validated['status'] ?? Product::STATUS_DRAFT;

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'data' => $product,
        ], 201);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'slug'            => 'sometimes|string|max:255|unique:products,slug,' . $product->id,
            'description'     => 'nullable|string',
            'sku'             => 'nullable|string|max:100',
            'source_url'      => 'nullable|url|max:1000',
            'requires_render' => 'nullable|boolean',
            'price_cents'     => 'sometimes|integer|min:0',
            'cost_cents'      => 'nullable|integer|min:0',
            'markup_percent'  => 'nullable|numeric|min:0|max:999.99',
            'weight_kg'       => 'sometimes|numeric|min:0.01|max:50',
            'length_cm'       => 'sometimes|numeric|min:0.1|max:200',
            'width_cm'        => 'sometimes|numeric|min:0.1|max:200',
            'height_cm'       => 'sometimes|numeric|min:0.1|max:200',
            'stock'           => 'sometimes|integer|min:0',
            'status'          => 'sometimes|in:draft,active,inactive,sold_out',
            'available_until' => 'nullable|date',
            'category'        => 'nullable|string|max:100',
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'data' => $product->fresh(),
        ]);
    }

    public function destroy(Product $product)
    {
        // Soft delete via status flip (preserves order history)
        $product->update(['status' => Product::STATUS_INACTIVE]);

        return response()->json([
            'success' => true,
            'message' => 'Product deactivated',
        ]);
    }

    /**
     * Bulk soft-delete — flip status to inactive for many products at once.
     * Mirrors the single destroy() so order history is preserved.
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:products,id',
        ]);

        $count = Product::whereIn('id', $validated['ids'])
            ->update(['status' => Product::STATUS_INACTIVE]);

        Log::info('Bulk-deactivated products', [
            'count'    => $count,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} products deactivated",
            'count'   => $count,
        ]);
    }

    /**
     * Bulk restore — flip status from inactive back to active. Companion to
     * bulkDestroy() for un-doing soft deletes.
     */
    public function bulkRestore(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:products,id',
        ]);

        $count = Product::whereIn('id', $validated['ids'])
            ->update(['status' => Product::STATUS_ACTIVE]);

        Log::info('Bulk-restored products', [
            'count'    => $count,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} products restored",
            'count'   => $count,
        ]);
    }

    /**
     * Permanently delete a product (the row, its variants, and its Spaces
     * images). Only allowed for already-inactive products — the soft-delete
     * step is a deliberate safety net users have to pass through first.
     *
     * Order history is unaffected: order_items snapshot product_name,
     * product_url, and product_image_url, so deleting the source product
     * doesn't break past order pages.
     */
    public function forceDestroy(Product $product)
    {
        if ($product->status !== Product::STATUS_INACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Product must be inactive (soft-deleted) before it can be permanently deleted.',
            ], 422);
        }

        $this->deleteProductImagesFromSpaces($product);
        $product->delete(); // cascades product_variants

        Log::info('Force-deleted product', ['product_id' => $product->id]);

        return response()->json([
            'success' => true,
            'message' => 'Product permanently deleted',
        ]);
    }

    public function bulkForceDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:products,id',
        ]);

        $products = Product::whereIn('id', $validated['ids'])->get();

        $deleted = [];
        $skipped = [];

        foreach ($products as $product) {
            if ($product->status !== Product::STATUS_INACTIVE) {
                $skipped[] = ['id' => $product->id, 'reason' => "not inactive (was {$product->status})"];
                continue;
            }

            try {
                $this->deleteProductImagesFromSpaces($product);
                $product->delete();
                $deleted[] = $product->id;
            } catch (\Exception $e) {
                Log::error('Force-delete failed for product', ['product_id' => $product->id, 'error' => $e->getMessage()]);
                $skipped[] = ['id' => $product->id, 'reason' => 'error: ' . $e->getMessage()];
            }
        }

        Log::info('Bulk force-deleted products', [
            'deleted'  => count($deleted),
            'skipped'  => count($skipped),
            'admin_id' => $request->user()->id,
        ]);

        $msg = count($deleted) . " products permanently deleted";
        if (count($skipped) > 0) {
            $msg .= " · " . count($skipped) . " skipped (not inactive)";
        }

        return response()->json([
            'success' => true,
            'message' => $msg,
            'count'   => count($deleted),
            'deleted' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Best-effort delete of every image file in product.images from Spaces.
     * Failures are logged but don't block the row delete — once the row is
     * gone, the URLs are unreachable anyway.
     */
    private function deleteProductImagesFromSpaces(Product $product): void
    {
        $images = $product->images ?? [];
        foreach ($images as $img) {
            if (empty($img['path'])) continue;
            try {
                Storage::disk('spaces')->delete($img['path']);
            } catch (\Exception $e) {
                Log::warning('Failed to delete product image from Spaces', [
                    'product_id' => $product->id,
                    'path'       => $img['path'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Multi-image upload to Spaces. Appends to product.images JSON.
     */
    public function uploadImages(Request $request, Product $product)
    {
        $request->validate([
            'images'    => 'required|array|min:1|max:10',
            'images.*'  => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        try {
            $storagePath = "products/{$product->slug}";
            $existing = $product->images ?? [];
            $startOrder = count($existing);

            foreach ($request->file('images') as $i => $file) {
                $filename = "img-" . ($startOrder + $i + 1) . "-" . time() . "." . $file->getClientOriginalExtension();
                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url  = config('filesystems.disks.spaces.url') . '/' . $path;

                $existing[] = [
                    'path'  => $path,
                    'url'   => $url,
                    'order' => $startOrder + $i,
                ];
            }

            $product->update(['images' => $existing]);

            return response()->json([
                'success' => true,
                'data' => $product->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Product image upload failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Image upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a single image by index from product.images JSON + remove from Spaces.
     */
    public function deleteImage(Request $request, Product $product, int $index)
    {
        $images = $product->images ?? [];

        if (! isset($images[$index])) {
            return response()->json(['success' => false, 'message' => 'Image not found'], 404);
        }

        $img = $images[$index];

        try {
            if (! empty($img['path'])) {
                Storage::disk('spaces')->delete($img['path']);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete image from Spaces', ['error' => $e->getMessage()]);
        }

        array_splice($images, $index, 1);
        // Re-number order
        foreach ($images as $i => &$image) {
            $image['order'] = $i;
        }

        $product->update(['images' => $images]);

        return response()->json([
            'success' => true,
            'data' => $product->fresh(),
        ]);
    }

    /**
     * Products with available_until ≤ N days from now (return-to-retailer dashboard).
     */
    public function expiring(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $products = Product::expiringSoon($days)
            ->orderBy('available_until')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }
}
