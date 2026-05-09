<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Gender;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'gender_id'     => 'nullable|integer|exists:genders,id',
            'gender_slug'   => 'nullable|string|max:120',
            'search'        => 'nullable|string|max:200',
            'sort'          => 'nullable|in:newest,price_asc,price_desc',
        ]);

        $perPage = (int) $request->input('per_page', 24);

        $query = Product::listed()->with([
            'variants' => fn ($q) => $q->orderBy('display_order')->orderBy('id'),
            'store',
            'gender',
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

        // Gender filter — direct FK, accepts id or slug
        if ($genderId = $request->input('gender_id')) {
            $query->where('gender_id', $genderId);
        } elseif ($genderSlug = $request->input('gender_slug')) {
            $gender = Gender::active()->where('slug', $genderSlug)->first();
            $query->where('gender_id', $gender?->id ?? 0);
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
                'gender',
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
                    'gender',
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
     * For categories without an admin-uploaded image, image_url is
     * backfilled with the first listed product's first image so the UI
     * never has to render an empty placeholder.
     */
    public function categories()
    {
        $categories = Category::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'image_url']);

        $this->backfillImageUrls(
            $categories,
            fn ($missing) => DB::table('category_product')
                ->join('products', 'products.id', '=', 'category_product.product_id')
                ->whereIn('category_product.category_id', $missing)
                ->where('products.status', Product::STATUS_ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('products.available_until')
                      ->orWhere('products.available_until', '>', now());
                })
                ->orderByDesc('products.created_at')
                ->get(['category_product.category_id as group_id', 'products.images']),
        );

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * Public list of active stores — used by storefront filter dropdowns,
     * the brand index page, and (with on_landing=1) the landing-page
     * brand showcase. The `limit` param caps the result count.
     */
    public function stores(\Illuminate\Http\Request $request)
    {
        $query = Store::active();

        if ($request->boolean('on_landing')) {
            $query->where('show_on_landing', true);
        }

        $query->orderBy('sort_order')->orderBy('name');

        if ($limit = (int) $request->input('limit', 0)) {
            $query->limit($limit);
        }

        $stores = $query->get(['id', 'name', 'slug', 'base_url', 'logo_url', 'cover_image_url', 'description']);

        return response()->json(['success' => true, 'data' => $stores]);
    }

    /**
     * Public list of active genders — used by storefront filter.
     * Backfills image_url with a representative product image when no
     * admin-uploaded image exists.
     */
    public function genders()
    {
        $genders = Gender::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'image_url']);

        $this->backfillImageUrls(
            $genders,
            fn ($missing) => DB::table('products')
                ->whereIn('gender_id', $missing)
                ->where('status', Product::STATUS_ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('available_until')
                      ->orWhere('available_until', '>', now());
                })
                ->orderByDesc('created_at')
                ->get(['gender_id as group_id', 'images']),
        );

        return response()->json(['success' => true, 'data' => $genders]);
    }

    /**
     * For each item without an image_url, look up a representative
     * product image and stamp it in. Single batched query (no N+1) —
     * the closure receives the array of missing ids and must return a
     * collection of rows shaped { group_id, images } where images is
     * the product's JSON-encoded image array.
     *
     * Items keep their existing image_url if one is set; missing items
     * with no associated products simply stay null and the frontend
     * falls back to its own placeholder.
     */
    private function backfillImageUrls($items, callable $fetcher): void
    {
        $missing = $items->whereNull('image_url')->pluck('id')->all();
        if (empty($missing)) return;

        // Group products by their parent id, take the first (newest) per group
        $rows = $fetcher($missing);
        $byId = $rows->groupBy('group_id')->map(function ($group) {
            $images = json_decode($group->first()->images ?? '[]', true) ?? [];
            return $images[0]['url'] ?? null;
        });

        foreach ($items as $item) {
            if (! $item->image_url && $byId->has($item->id)) {
                $item->image_url = $byId->get($item->id);
            }
        }
    }
}
