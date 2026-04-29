<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class StoreProductController extends Controller
{
    /**
     * Fields that must NEVER appear in public responses (admin-only).
     */
    private const HIDDEN_FROM_PUBLIC = [
        'cost_cents',
        'markup_percent',
        'source_url',
        'last_stock_check_response',
    ];

    private const HIDDEN_VARIANT_FROM_PUBLIC = [
        'shopify_variant_id',
        'last_stock_check_response',
    ];

    /**
     * Public Boxly Store product list — paginated, filterable.
     * Uses listed() scope so out-of-stock products still show in storefront
     * (with WhatsApp CTA on the frontend).
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

        $query = Product::listed();

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

        $query->with(['variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id')]);

        $products = $query->paginate($perPage)->withQueryString();
        $products->getCollection()->each(function ($p) {
            $p->makeHidden(self::HIDDEN_FROM_PUBLIC);
            $p->variants?->each->makeHidden(self::HIDDEN_VARIANT_FROM_PUBLIC);
        });

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Public product detail by slug. No auth required — shareable links.
     * Out-of-stock products are still returned so the page can show WhatsApp CTA.
     */
    public function show(string $slug)
    {
        $product = Product::listed()
            ->with(['variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id')])
            ->where('slug', $slug)
            ->firstOrFail();
        $product->makeHidden(self::HIDDEN_FROM_PUBLIC);
        $product->variants?->each->makeHidden(self::HIDDEN_VARIANT_FROM_PUBLIC);

        $related = collect();
        if ($product->category) {
            $related = Product::listed()
                ->with(['variants' => fn ($q) => $q->orderBy('display_order')])
                ->where('category', $product->category)
                ->where('id', '!=', $product->id)
                ->limit(8)
                ->get();
            $related->each(function ($p) {
                $p->makeHidden(self::HIDDEN_FROM_PUBLIC);
                $p->variants?->each->makeHidden(self::HIDDEN_VARIANT_FROM_PUBLIC);
            });
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
        $categories = Product::listed()
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
