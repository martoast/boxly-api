<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class StoreProductController extends Controller
{
    /**
     * Public Boxly Store product list — paginated, filterable.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:60',
            'category' => 'nullable|string|max:100',
            'search'   => 'nullable|string|max:200',
            'sort'     => 'nullable|in:newest,price_asc,price_desc',
        ]);

        $perPage = (int) $request->input('per_page', 24);

        $query = Product::available();

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        switch ($request->input('sort', 'newest')) {
            case 'price_asc':  $query->orderBy('price_cents', 'asc'); break;
            case 'price_desc': $query->orderBy('price_cents', 'desc'); break;
            default:           $query->latest();
        }

        $products = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Public product detail by slug. No auth required — shareable links.
     */
    public function show(string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $related = collect();
        if ($product->category) {
            $related = Product::available()
                ->where('category', $product->category)
                ->where('id', '!=', $product->id)
                ->limit(8)
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product,
                'related' => $related,
            ],
        ]);
    }

    public function categories()
    {
        $categories = Product::available()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
