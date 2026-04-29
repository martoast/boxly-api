<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
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
        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'slug'            => 'nullable|string|max:255|unique:products,slug',
            'description'     => 'nullable|string',
            'sku'             => 'nullable|string|max:100',
            'source_url'      => 'nullable|url|max:1000',
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
