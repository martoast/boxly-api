<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class AdminCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query();

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
        return response()->json(['success' => true, 'data' => $category]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:categories,slug',
            'description' => 'nullable|string',
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
}
