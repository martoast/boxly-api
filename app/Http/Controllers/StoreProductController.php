<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
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
    ];

    private const HIDDEN_VARIANT_FROM_PUBLIC = [
        'shopify_variant_id',
    ];

    /**
     * Public Boxly Store product list — paginated, filterable.
     * Uses listed() scope so out-of-stock products still show in storefront
     * (with WhatsApp CTA on the frontend).
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page'      => 'nullable|integer|min:1|max:60',
            'category_id'   => 'nullable|integer|exists:categories,id',
            'category_slug' => 'nullable|string|max:120',
            'store_id'      => 'nullable|integer|exists:stores,id',
            'store_slug'    => 'nullable|string|max:120',
            'search'        => 'nullable|string|max:200',
            'sort'          => 'nullable|in:newest,price_asc,price_desc',
        ]);

        $perPage = (int) $request->input('per_page', 24);

        $query = Product::listed()->with([
            'variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id'),
            'store',
            'categories',
        ]);

        // Store filter — accepts id or slug
        if ($storeId = $request->input('store_id')) {
            $query->where('store_id', $storeId);
        } elseif ($storeSlug = $request->input('store_slug')) {
            $store = Store::active()->where('slug', $storeSlug)->first();
            $query->where('store_id', $store?->id ?? 0);
        }

        // Category filter — accepts id or slug
        if ($categoryId = $request->input('category_id')) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $categoryId));
        } elseif ($categorySlug = $request->input('category_slug')) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.slug', $categorySlug));
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
            ->with([
                'variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id'),
                'store',
                'categories',
            ])
            ->where('slug', $slug)
            ->firstOrFail();
        $product->makeHidden(self::HIDDEN_FROM_PUBLIC);
        $product->variants?->each->makeHidden(self::HIDDEN_VARIANT_FROM_PUBLIC);

        // Related = other products sharing any of this product's categories
        $categoryIds = $product->categories->pluck('id');
        $related = collect();
        if ($categoryIds->isNotEmpty()) {
            $related = Product::listed()
                ->with([
                    'variants' => fn ($q) => $q->orderBy('display_order'),
                    'store',
                    'categories',
                ])
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
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

    /**
     * Public list of active categories — used by storefront filter dropdowns.
     */
    public function categories()
    {
        $categories = Category::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'image_url']);

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * Public list of active stores — used by storefront filter dropdowns
     * and the brand index page.
     */
    public function stores()
    {
        $stores = Store::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'base_url', 'logo_url', 'cover_image_url', 'description']);

        return response()->json(['success' => true, 'data' => $stores]);
    }
}
