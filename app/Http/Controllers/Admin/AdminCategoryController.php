<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()->withCount('products');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function show(Category $category)
    {
        return response()->json(['success' => true, 'data' => $category->loadCount('products')]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:categories,slug',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|url|max:500',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $category = Category::create($validated);
        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'sometimes|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string',
            'image_url'   => 'nullable|url|max:500',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $category->update($validated);
        return response()->json(['success' => true, 'data' => $category->fresh()]);
    }

    public function destroy(Category $category)
    {
        $category->delete(); // cascades pivot rows
        return response()->json(['success' => true, 'message' => 'Category deleted']);
    }

    public function uploadImage(Request $request, Category $category)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        try {
            $file = $request->file('image');
            $path = "categories/{$category->slug}/img-" . time() . '.' . $file->getClientOriginalExtension();
            Storage::disk('spaces')->putFileAs(dirname($path), $file, basename($path), 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $category->update(['image_url' => $url]);
            return response()->json(['success' => true, 'data' => $category->fresh()]);
        } catch (\Exception $e) {
            Log::error('Category image upload failed', ['category_id' => $category->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
