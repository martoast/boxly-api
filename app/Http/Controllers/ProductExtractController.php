<?php

namespace App\Http\Controllers;

use App\Models\SearchEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Extract clean product details from a US-store product URL for the shopping
 * assistant: title, price (USD), image, store. Uses ScraperAPI to bypass
 * Cloudflare, then parses Shopify product JSON or schema.org JSON-LD.
 *
 * Public + rate-limited. Best-effort: returns what it can; the assistant can
 * fall back to asking the user for the price.
 */
class ProductExtractController extends Controller
{
    public function extract(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2000',
        ]);

        $url = $validated['url'];
        $store = $this->storeFromUrl($url);

        $html = $this->fetch($url);
        if (! $html) {
            return response()->json([
                'success' => false,
                'message' => 'Could not reach the product page.',
                'data' => ['source_url' => $url, 'store' => $store],
            ], 422);
        }

        $product = $this->parseShopify($url) ?? $this->parseJsonLd($html) ?? $this->parseMeta($html);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Could not parse product details from the page.',
                'data' => ['source_url' => $url, 'store' => $store],
            ], 422);
        }

        // Fallbacks for non-Shopify / headless stores (e.g. Gymshark) that keep
        // the price/image in an app-state blob rather than the Product JSON-LD.
        if (empty($product['price'])) {
            $product['price'] = $this->priceFromHtml($html);
        }
        if (empty($product['image'])) {
            $product['image'] = $this->meta($html, 'og:image') ?? $this->meta($html, 'twitter:image');
        }

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'source_url' => $url,
                'store' => $store,
                'currency' => 'USD',
            ], $product),
        ]);
    }

    /**
     * Universal product search across the ENTIRE US market via Google Shopping.
     * Works for ANY store/brand regardless of platform (Shopify, headless,
     * JS-rendered, Cloudflare-protected) because Google already crawled them.
     * Primary engine is SerpAPI (fast + reliable); ScraperAPI's structured
     * endpoint is the fallback. Returns normalized products with image, USD
     * price, and the source store for each.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query'     => 'required|string|max:200',
            'store'     => 'nullable|string|max:100',
            'limit'     => 'nullable|integer|min:1|max:40',
            'start'     => 'nullable|integer|min:0|max:400',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'sale'      => 'nullable|boolean',
        ]);

        $limit = $validated['limit'] ?? 40;
        $start = (int) ($validated['start'] ?? 0);
        $store = trim($validated['store'] ?? '');
        // Bias the query toward the store when one is specified.
        $baseQ = $store !== '' ? $store . ' ' . $validated['query'] : $validated['query'];

        // Customers know where the deals are (Target, Walmart, Dick's…). A plain
        // Google Shopping search often misses those exact stores, so we also run a
        // category-aware pass at the priority retailers and surface them first.
        // Skipped when the user already fixed a specific store.
        $retailers = $store !== '' ? [] : $this->priorityRetailers($validated['query']);

        // One Google Shopping query per source, fetched in parallel (cached each).
        $queries = array_merge([$baseQ], array_map(fn ($r) => $baseQ . ' ' . $r, $retailers));
        $byQuery = $this->multiShopping($queries, $start);

        $general = $byQuery[$baseQ] ?? [];
        // ScraperAPI fallback when SerpAPI returns nothing for the base query.
        if (empty($general) && $start === 0) {
            $fb = $this->shoppingViaScraperapi($baseQ);
            if (is_array($fb)) {
                $general = $fb;
            }
        }

        // Keep each retailer's ACTUAL listings (the biased query returns a mix —
        // filter to items whose store matches) and tag them to float to the top.
        // Each priority retailer's listings in the store's own relevance order
        // (Google already ranked the biased query), capped so none dominates.
        $priorityLists = [];
        foreach ($retailers as $r) {
            $needle = $this->slugify($r);
            $list = [];
            foreach (($byQuery[$baseQ . ' ' . $r] ?? []) as $p) {
                $src = $this->slugify((string) ($p['store'] ?? ''));
                if ($src !== '' && (str_contains($src, $needle) || str_contains($needle, $src))) {
                    $list[] = $p;
                    if (count($list) >= 8) {
                        break;
                    }
                }
            }
            if ($list) {
                $priorityLists[] = $list;
            }
        }

        // RELEVANCE FIRST: lead with the general (Google-ranked) results so the
        // exact thing the user typed — including a color/attribute like "pink" —
        // stays on top, while still weaving in the priority stores. We do NOT
        // re-sort by deals here; that previously buried relevant items under
        // off-query "deal" products from a priority store.
        $products = $this->dedupeProducts($this->interleaveResults($general, $priorityLists));
        $hasMore = count($general) >= 30;

        // Budget filters are strict (price range).
        $min = isset($validated['min_price']) ? (float) $validated['min_price'] : null;
        $max = isset($validated['max_price']) ? (float) $validated['max_price'] : null;
        if ($min !== null || $max !== null) {
            $products = array_values(array_filter($products, function ($p) use ($min, $max) {
                $price = $p['price'] ?? null;
                if ($min !== null && ($price === null || $price < $min)) {
                    return false;
                }
                if ($max !== null && ($price === null || $price > $max)) {
                    return false;
                }
                return true;
            }));
        }

        // Ranking: surface the BEST, most TRUSTWORTHY results — not the cheapest.
        //   1) RELEVANCE tier (a color/attribute like "owala pink" keeps pink on top)
        //   2) STORE TRUST (big-box like Target/Walmart/Dick's, then major brands,
        //      then niche resellers) — customers trust the big names
        //   3) priced before unpriced
        // Within a tier the original Google-Shopping order (its own relevance/
        // quality) is preserved (PHP 8 sort is stable). We deliberately do NOT sort
        // by lowest price — that surfaced tiny niche resellers.
        $qTerms = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(trim($validated['query']))),
            fn ($t) => mb_strlen($t) >= 2
        ));
        $multiTerm = count($qTerms) >= 2;
        $termScore = function ($p) use ($qTerms) {
            $t = mb_strtolower((string) ($p['title'] ?? ''));
            $n = 0;
            foreach ($qTerms as $term) {
                if (str_contains($t, $term)) {
                    $n++;
                }
            }
            return $n;
        };
        usort($products, function ($a, $b) use ($multiTerm, $termScore) {
            if ($multiTerm) {
                $sa = $termScore($a);
                $sb = $termScore($b);
                if ($sa !== $sb) {
                    return $sb <=> $sa; // more query terms matched first
                }
            }
            $ta = $this->storeTrust($a['store'] ?? null);
            $tb = $this->storeTrust($b['store'] ?? null);
            if ($ta !== $tb) {
                return $tb <=> $ta; // most trustworthy store first
            }
            // priced before unpriced; otherwise keep Google's order (stable sort)
            $ha = ($a['price'] ?? null) !== null ? 1 : 0;
            $hb = ($b['price'] ?? null) !== null ? 1 : 0;

            return $hb <=> $ha;
        });

        $shown = array_slice($products, 0, $limit);

        // Price range across the shown results, so the assistant can tell the
        // customer "I found options between $X and $Y".
        $prices = array_values(array_filter(array_map(fn ($p) => $p['price'] ?? null, $shown), fn ($v) => $v !== null));
        $range = $prices ? ['min' => min($prices), 'max' => max($prices)] : null;

        // Analytics: log the query AND the results we served (top items + stores)
        // so search quality can be analyzed query→results. Best-effort; first page
        // only. (Guest queries that bounce at login are captured at the landing.)
        if ($start === 0) {
            try {
                SearchEvent::create([
                    'type'           => SearchEvent::TYPE_SEARCH,
                    'query'          => mb_substr(trim($validated['query']), 0, 255),
                    'results'        => count($shown),
                    'results_sample' => array_map(fn ($p) => [
                        'store' => $p['store'] ?? null,
                        'title' => isset($p['title']) ? mb_substr((string) $p['title'], 0, 140) : null,
                        'price' => $p['price'] ?? null,
                    ], array_slice($shown, 0, 12)),
                ]);
            } catch (\Throwable $e) {
                // analytics must never break search
            }
        }

        return response()->json([
            'success' => true,
            'data'    => ['query' => $baseQ, 'products' => $shown, 'price_range' => $range, 'has_more' => $hasMore, 'start' => $start],
        ]);
    }

    /**
     * Full product detail for the modal: more images, description, and direct
     * seller links — fetched lazily (one SerpAPI call) when a product opens,
     * keyed by the immersive page token from search_products.
     */
    public function details(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|max:6000']);

        $key = config('services.serpapi.key');
        if (! $key) {
            return response()->json(['success' => false, 'message' => 'Not configured.'], 503);
        }

        try {
            $res = Http::timeout(40)->get('https://serpapi.com/search.json', [
                'engine'     => 'google_immersive_product',
                'page_token' => $validated['token'],
                'api_key'    => $key,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed.'], 502);
        }
        if (! $res->successful()) {
            return response()->json(['success' => false, 'message' => 'Failed.'], 502);
        }

        $pr = $res->json('product_results') ?? [];

        $images = array_values(array_filter(
            array_map(fn ($t) => is_array($t) ? ($t['link'] ?? null) : $t, $pr['thumbnails'] ?? []),
            fn ($v) => is_string($v) && str_starts_with($v, 'http')
        ));

        // First seller with a real link → a direct merchant URL (much better than
        // the Google view link for "see it" and for placing the request).
        $link = null;
        foreach ($pr['stores'] ?? [] as $s) {
            if (! empty($s['link'])) {
                $link = $s['link'];
                break;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'images'      => array_slice($images, 0, 8),
                'description' => $pr['about_the_product']['description'] ?? null,
                'link'        => $link,
            ],
        ]);
    }

    /**
     * Dynamic product page: build full detail on the fly for the results→detail
     * flow. Merges SerpAPI immersive (images/description/direct seller link) with
     * live Shopify variant data (sizes/prices/stock) when the seller is Shopify,
     * falling back to JSON-LD/meta for other stores. Input: { url?, token? }.
     */
    public function page(Request $request)
    {
        $validated = $request->validate([
            'url'   => 'nullable|string|max:2000',
            'token' => 'nullable|string|max:6000',
            'store' => 'nullable|string|max:120',
            'title' => 'nullable|string|max:255',
        ]);

        // Analytics: log the product view HERE (server-side) — the only caller is
        // the browser opening a product, and logging it here is reliable, unlike a
        // best-effort client POST that can silently fail. Fires once per open
        // (a refresh/back hits the client-side product cache and never calls this).
        $this->logProductView($request, $validated);

        // Server-side cache: reuse the scraped detail across users for a SHORT
        // window. 10 min keeps price/stock relevant (and the customer never pays
        // until Boxly confirms the quote) while saving SerpAPI + ScraperAPI calls.
        // Keyed by the product URL when present (stable per product), else token.
        $pdpKey = 'pdp:' . md5((! empty($validated['url']) ? ('u:' . $validated['url']) : ('t:' . ($validated['token'] ?? ''))) . '|z:' . config('services.scraperapi.zip'));
        $cachedPdp = Cache::get($pdpKey);
        if ($cachedPdp !== null) {
            return response()->json(['success' => true, 'data' => $cachedPdp, 'cached' => true]);
        }

        $out = [
            'title' => null, 'price' => null, 'was' => null, 'on_sale' => false,
            'store' => null, 'buy_url' => $validated['url'] ?? null,
            'images' => [], 'description' => null,
            'options' => [], 'variants' => [], 'has_variants' => false, 'available' => true,
        ];

        // 1) SerpAPI immersive — extra images, description, and (crucially) a real
        //    merchant link instead of the Google view link.
        if (! empty($validated['token'])) {
            if ($imm = $this->immersive($validated['token'])) {
                $out['images']      = $imm['images'];
                $out['description'] = $imm['description'];
                if (empty($out['buy_url']) || $this->isGoogleLink($out['buy_url'])) {
                    $out['buy_url'] = $imm['link'] ?: $out['buy_url'];
                }
            }
        }

        $buy = $out['buy_url'];
        if ($buy) {
            $out['store'] = $this->storeFromUrl($buy);
        }

        // 2) Layered, platform-aware extraction — pull the real product as the
        //    store would render it (variants, sizes, colors, price, stock).
        //    Priority: structured APIs (Amazon/Walmart) → Shopify .js → HTML
        //    cascade (JSON-LD / WooCommerce / Magento), escalating to a rendered
        //    fetch when a plain fetch comes back thin.
        $detail = $buy && ! $this->isGoogleLink($buy) ? $this->extractDetail($buy) : null;
        if ($detail) {
            $out['title']        = $detail['title'] ?: $out['title'];
            $out['price']        = $detail['price'] ?? $out['price'];
            $out['was']          = $detail['was'] ?? $out['was'];
            $out['on_sale']      = $detail['on_sale'] ?? $out['on_sale'];
            $out['options']      = $detail['options'] ?? [];
            $out['variants']     = $detail['variants'] ?? [];
            $out['has_variants'] = count($out['variants']) > 0;
            if (! empty($detail['unavailable'])) {
                $out['available'] = false;
            }
            if (! empty($detail['description'])) {
                $out['description'] = $out['description'] ?: $detail['description'];
            }
            if (! empty($detail['images'])) {
                // Prefer store images (more, higher-res); keep immersive as backup.
                $out['images'] = array_merge($detail['images'], $out['images']);
            }
        }

        $out['images'] = array_slice(array_values(array_unique(array_filter($out['images']))), 0, 12);

        // Cache only a meaningful result (don't poison the cache with a failed/empty
        // scrape — the next view should retry).
        if (! empty($out['title']) || ! empty($out['images']) || $out['price'] !== null) {
            Cache::put($pdpKey, $out, now()->addMinutes(10));
        }

        return response()->json(['success' => true, 'data' => $out]);
    }

    /**
     * Record a product view for AI-search analytics. Best-effort — must never
     * break the product page. Resolves the signed-in user (cookie/sanctum) so the
     * dashboard can split guest vs account views; falls back to the URL host for
     * the store name when the caller didn't pass the search-card store label.
     */
    private function logProductView(Request $request, array $v): void
    {
        try {
            $url = $v['url'] ?? null;
            $store = ! empty($v['store'])
                ? mb_substr(trim($v['store']), 0, 120)
                : ($url ? $this->storeFromUrl($url) : null);

            SearchEvent::create([
                'user_id' => optional(auth('sanctum')->user())->id ?? optional($request->user())->id,
                'type'    => SearchEvent::TYPE_PRODUCT_VIEW,
                'store'   => $store,
                'title'   => ! empty($v['title']) ? mb_substr(trim($v['title']), 0, 255) : null,
                'url'     => $url ? mb_substr($url, 0, 2000) : null,
            ]);
        } catch (\Throwable $e) {
            // analytics must never break the product page
        }
    }

    /**
     * Route a product URL to the best available extractor and return normalized
     * detail: { title, price, was, on_sale, description, options[], variants[],
     * images[] }. Each extractor returns the same shape; null when it can't.
     */
    private function extractDetail(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'amazon.')) {
            return $this->amazonStructured($url);
        }
        if (str_contains($host, 'walmart.')) {
            return $this->walmartStructured($url);
        }
        // Shopify exposes clean variant JSON — best source when present.
        if ($sh = $this->shopifyVariants($url)) {
            $sh['description'] = $sh['description'] ?? null;
            return $sh;
        }

        return $this->htmlCascade($url);
    }

    /** Amazon: ScraperAPI structured product (ASIN) — options, price, stock, images. */
    private function amazonStructured(string $url): ?array
    {
        $key = config('services.scraperapi.key');
        if (! $key) {
            return null;
        }
        if (! preg_match('~/(?:dp|gp/product|gp/aw/d|product)/([A-Z0-9]{10})~i', $url, $m)
            && ! preg_match('~[/?]([A-Z0-9]{10})(?:[/?&]|$)~', $url, $m)) {
            return null;
        }
        try {
            $res = Http::timeout(70)->get('https://api.scraperapi.com/structured/amazon/product', [
                'api_key' => $key, 'asin' => $m[1], 'country' => 'us',
                'zip_code' => config('services.scraperapi.zip'), // warehouse-local price/stock
            ]);
            if (! $res->successful()) {
                return null;
            }
            $d = $res->json();
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($d) || empty($d['name'])) {
            return null;
        }

        $price = $this->money($d['pricing'] ?? null);
        $was = $this->money($d['list_price'] ?? null);
        $onSale = $was && $price && $was > $price;

        $images = array_values(array_unique(array_filter(
            array_merge($d['high_res_images'] ?? [], $d['images'] ?? []),
            fn ($i) => is_string($i) && str_starts_with($i, 'http')
        )));

        $options = [];
        foreach (($d['customization_options'] ?? []) as $name => $vals) {
            if (! is_array($vals)) {
                continue;
            }
            $values = array_values(array_filter(array_map(
                fn ($v) => is_array($v) ? ($v['value'] ?? null) : (is_string($v) ? $v : null),
                $vals
            )));
            if ($values) {
                $options[] = ['name' => $this->optionLabel((string) $name), 'values' => $values];
            }
        }

        $bullets = $d['feature_bullets'] ?? null;

        return [
            'title'       => $d['name'],
            'price'       => $price,
            'was'         => $onSale ? $was : null,
            'on_sale'     => $onSale,
            'description' => is_array($bullets) && $bullets ? '• ' . implode("\n• ", array_slice($bullets, 0, 6)) : null,
            'options'     => $options,   // selectable attributes (no per-combo matrix)
            'variants'    => [],         // Amazon needs a call per ASIN — selection saved as a note
            'images'      => array_slice($images, 0, 12),
        ];
    }

    /** Walmart: ScraperAPI structured product (item id) — price, stock, images. */
    private function walmartStructured(string $url): ?array
    {
        $key = config('services.scraperapi.key');
        if (! $key) {
            return null;
        }
        if (! preg_match('~/ip/(?:[^/]+/)?(\d{6,})~', $url, $m) && ! preg_match('~(\d{8,})~', $url, $m)) {
            return null;
        }
        try {
            $res = Http::timeout(70)->get('https://api.scraperapi.com/structured/walmart/product', [
                'api_key' => $key, 'product_id' => $m[1],
                'zip_code' => config('services.scraperapi.zip'), // warehouse-local price/stock
            ]);
            if (! $res->successful()) {
                return null;
            }
            $d = $res->json();
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($d) || empty($d['product_name'])) {
            return null;
        }

        $price = $this->money($d['price'] ?? null);
        $was = $this->money($d['old_price'] ?? null);
        $onSale = $was && $price && $was > $price;
        $av = strtoupper((string) ($d['product_availability'] ?? ''));
        $available = $av === '' || (str_contains($av, 'IN_STOCK') || str_contains($av, 'AVAILABLE'));

        $images = array_values(array_filter(($d['images'] ?? []), fn ($i) => is_string($i) && str_starts_with($i, 'http')));

        // Build option lists from Walmart variants when the product has them.
        $options = [];
        foreach (($d['variants'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $name = $this->optionLabel((string) ($v['name'] ?? $v['type'] ?? 'Opción'));
            $values = [];
            foreach (($v['values'] ?? $v['options'] ?? []) as $val) {
                $label = is_array($val) ? ($val['name'] ?? $val['value'] ?? null) : $val;
                if ($label) {
                    $values[] = $label;
                }
            }
            if ($values) {
                $options[] = ['name' => $name, 'values' => array_values(array_unique($values))];
            }
        }

        return [
            'title'       => $d['product_name'],
            'price'       => $price,
            'was'         => $onSale ? $was : null,
            'on_sale'     => $onSale,
            'description' => $d['product_short_description'] ?? null,
            'options'     => $options,
            'variants'    => [],
            'images'      => array_slice($images, 0, 12),
            'unavailable' => ! $available,
        ];
    }

    /**
     * Generic HTML extraction for every other store: fetch (escalating to a
     * rendered fetch when the plain HTML is thin/JS-only), then pull variants
     * from WooCommerce / Magento / JSON-LD, plus images and base price.
     */
    private function htmlCascade(string $url): ?array
    {
        // Single, fast plain fetch only. A rendered (JS) fetch costs ~30–70s and
        // rarely yields variants for arbitrary stores — it made the product page
        // hang. We already have images/description/price from the immersive call
        // and the search card, so a thin result just means "no extra variants".
        $html = $this->fetch($url, false);
        $r = $html ? $this->extractFromHtml($html, $url) : null;

        // One retry on a failed/empty fetch — ScraperAPI occasionally returns a
        // blocked/empty page transiently, which would otherwise drop us to the
        // manual size box even for a fully-supported store (e.g. New Balance).
        if (! $r) {
            $html = $this->fetch($url, false);
            $r = $html ? $this->extractFromHtml($html, $url) : null;
        }

        return $r;
    }

    private function extractFromHtml(string $html, string $url): ?array
    {
        $base = [
            'title' => null, 'price' => null, 'was' => null, 'on_sale' => false,
            'description' => null, 'options' => [], 'variants' => [], 'images' => [],
        ];

        // Variants — first platform that yields a matrix wins.
        $matrix = $this->wooVariants($html) ?? $this->magentoVariants($html) ?? $this->nextDataVariants($html)
            ?? $this->nbVariants($html, $url) ?? $this->jsonLdVariants($html);
        if ($matrix) {
            $base = $this->mergeDetail($base, $matrix);
        }

        // Base title/price/image from structured data when still missing.
        if (! $base['title'] || $base['price'] === null) {
            if ($p = ($this->parseJsonLd($html) ?? $this->parseMeta($html))) {
                $base['title'] = $base['title'] ?: ($p['title'] ?? null);
                $base['price'] = $base['price'] ?? ($p['price'] ?? null);
                if (! empty($p['image'])) {
                    $base['images'][] = $p['image'];
                }
            }
        }

        $base['images'] = array_merge($base['images'], $this->gatherImages($html));
        $base['images'] = array_slice(array_values(array_unique(array_filter($base['images']))), 0, 12);

        $hasAnything = $base['title'] || $base['price'] !== null || $base['variants'] || $base['options'];
        return $hasAnything ? $base : null;
    }

    /** WooCommerce: the variations form embeds the full variant JSON. */
    private function wooVariants(string $html): ?array
    {
        if (! preg_match('~data-product_variations\s*=\s*([\'"])(.*?)\1~s', $html, $m)) {
            return null;
        }
        $json = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
        $vars = json_decode($json, true);
        if (! is_array($vars) || ! $vars) {
            return null;
        }

        $variants = [];
        $optionVals = [];
        $minPrice = null;
        $minWas = null;
        $anySale = false;
        foreach ($vars as $v) {
            if (! is_array($v)) {
                continue;
            }
            $price = isset($v['display_price']) ? (float) $v['display_price'] : null;
            $reg = isset($v['display_regular_price']) ? (float) $v['display_regular_price'] : null;
            $onSale = $reg && $price && $reg > $price;
            if ($price !== null && ($minPrice === null || $price < $minPrice)) {
                $minPrice = $price;
            }
            if ($onSale) {
                $anySale = true;
                $minWas = $minWas === null ? $reg : min($minWas, $reg);
            }
            $opts = [];
            foreach (($v['attributes'] ?? []) as $attrKey => $attrVal) {
                if ($attrVal === '' || $attrVal === null) {
                    continue;
                }
                $name = $this->optionLabel(preg_replace('~^attribute_(pa_)?~', '', (string) $attrKey));
                $opts[$name] = (string) $attrVal;
                $optionVals[$name][$attrVal] = true;
            }
            $variants[] = [
                'id'        => $v['variation_id'] ?? null,
                'title'     => implode(' / ', array_values($opts)),
                'options'   => $opts,
                'price'     => $price,
                'was'       => $onSale ? $reg : null,
                'on_sale'   => $onSale,
                'available' => (bool) ($v['is_in_stock'] ?? true),
            ];
        }
        if (! $variants) {
            return null;
        }
        $options = [];
        foreach ($optionVals as $name => $vals) {
            $options[] = ['name' => $name, 'values' => array_keys($vals)];
        }

        return [
            'title' => null, 'description' => null, 'images' => [],
            'price' => $minPrice, 'was' => $anySale ? $minWas : null, 'on_sale' => $anySale,
            'options' => $options, 'variants' => $variants,
        ];
    }

    /** Magento: the swatch/config script embeds jsonConfig with options + prices. */
    private function magentoVariants(string $html): ?array
    {
        if (! preg_match('~"jsonConfig"\s*:\s*(\{.*?\})\s*,\s*"~s', $html, $m)
            && ! preg_match('~Magento_Swatches/js/swatch-renderer["\']\s*:\s*(\{.*?\})\s*\}\s*\}~s', $html, $m)) {
            return null;
        }
        $cfg = json_decode($m[1], true);
        if (! is_array($cfg) || empty($cfg['attributes'])) {
            return null;
        }

        $options = [];
        foreach ($cfg['attributes'] as $attr) {
            if (! is_array($attr) || empty($attr['options'])) {
                continue;
            }
            $values = array_values(array_filter(array_map(
                fn ($o) => is_array($o) ? ($o['label'] ?? null) : null,
                $attr['options']
            )));
            if ($values) {
                $options[] = ['name' => $this->optionLabel((string) ($attr['label'] ?? 'Opción')), 'values' => $values];
            }
        }
        if (! $options) {
            return null;
        }

        $prices = $cfg['optionPrices'] ?? [];
        $finals = [];
        foreach ($prices as $p) {
            if (isset($p['finalPrice']['amount'])) {
                $finals[] = (float) $p['finalPrice']['amount'];
            }
        }

        return [
            'title' => null, 'description' => null, 'images' => [],
            'price' => $finals ? min($finals) : null, 'was' => null, 'on_sale' => false,
            'options' => $options, 'variants' => [],
        ];
    }

    /**
     * Next.js stores embed the full product in a __NEXT_DATA__ script (the .js
     * feed 404s on these custom frontends). Handles two shapes:
     *  A) Gymshark: productData.product.availableSizes (per-size stock).
     *  B) Shopify Storefront-style (Vuori/Hydrogen): a node with options[] +
     *     variants[].selectedOptions + availableForSale — full Color/Size/Length.
     */
    private function nextDataVariants(string $html): ?array
    {
        if (! preg_match('~<script id="__NEXT_DATA__"[^>]*>(.*?)</script>~s', $html, $m)) {
            return null;
        }
        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            return null;
        }

        $r = $this->variantsFromAvailableSizes($data['props']['pageProps']['productData']['product'] ?? null);
        if ($r) {
            return $r;
        }

        $node = $this->findStorefrontProduct($data);

        return $node ? $this->variantsFromStorefront($node) : null;
    }

    /** Shape A — Gymshark's productData.product.availableSizes. */
    private function variantsFromAvailableSizes($product): ?array
    {
        if (! is_array($product) || ! is_array($product['availableSizes'] ?? null) || ! $product['availableSizes']) {
            return null;
        }
        $compare = isset($product['compareAtPrice']) ? (float) $product['compareAtPrice'] : null;
        $variants = [];
        $values = [];
        $minPrice = null;
        foreach ($product['availableSizes'] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $size = strtoupper(trim((string) ($s['size'] ?? '')));
            if ($size === '') {
                continue;
            }
            $price = isset($s['price']) ? (float) $s['price'] : null;
            if ($price !== null && ($minPrice === null || $price < $minPrice)) {
                $minPrice = $price;
            }
            $onSale = $compare && $price && $compare > $price;
            $values[$size] = true;
            $variants[] = [
                'id' => $s['id'] ?? null, 'title' => $size, 'options' => ['Talla' => $size],
                'price' => $price, 'was' => $onSale ? $compare : null, 'on_sale' => (bool) $onSale,
                'available' => (bool) ($s['inStock'] ?? false),
            ];
        }
        if (! $variants) {
            return null;
        }
        $images = [];
        foreach (($product['media'] ?? []) as $md) {
            $src = is_array($md) ? ($md['src'] ?? null) : null;
            if (is_string($src) && str_starts_with($src, 'http')) {
                $images[] = $src;
            }
        }
        $anySale = $compare && $minPrice && $compare > $minPrice;

        return [
            'title' => $product['title'] ?? null,
            'description' => isset($product['description']) ? trim(strip_tags((string) $product['description'])) : null,
            'images' => array_slice(array_values(array_unique($images)), 0, 10),
            'price' => $minPrice, 'was' => $anySale ? $compare : null, 'on_sale' => (bool) $anySale,
            'options' => [['name' => 'Talla', 'values' => array_keys($values)]],
            'variants' => $variants,
        ];
    }

    /** Find the first node shaped like a Shopify Storefront product. */
    private function findStorefrontProduct($node): ?array
    {
        if (is_array($node)) {
            $opts = $node['options'] ?? null;
            $vars = $node['variants'] ?? null;
            if (is_array($opts) && $opts && is_array($vars) && $vars
                && isset($opts[0]['name'], $vars[0]) && is_array($vars[0]) && isset($vars[0]['selectedOptions'])) {
                return $node;
            }
            foreach ($node as $child) {
                if (is_array($child) && ($found = $this->findStorefrontProduct($child))) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** Shape B — Shopify Storefront: options[] + variants[].selectedOptions. */
    private function variantsFromStorefront(array $node): ?array
    {
        $options = [];
        foreach (($node['options'] ?? []) as $o) {
            $name = is_array($o) ? ($o['name'] ?? null) : null;
            $vals = is_array($o) ? ($o['values'] ?? null) : null;
            if ($name && is_array($vals) && $vals) {
                $options[] = ['name' => $this->optionLabel((string) $name), 'values' => array_values($vals)];
            }
        }

        $variants = [];
        $minPrice = null;
        $minCompare = null;
        $anySale = false;
        foreach (($node['variants'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $opts = [];
            foreach (($v['selectedOptions'] ?? []) as $so) {
                $n = $so['name'] ?? null;
                $val = $so['value'] ?? null;
                if ($n !== null && $val !== null) {
                    $opts[$this->optionLabel((string) $n)] = $val;
                }
            }
            $price = $this->moneyField($v['price'] ?? null);
            $compare = $this->moneyField($v['compareAtPrice'] ?? null);
            $onSale = $compare && $price && $compare > $price;
            if ($onSale) {
                $anySale = true;
                $minCompare = $minCompare === null ? $compare : min($minCompare, $compare);
            }
            if ($price !== null && ($minPrice === null || $price < $minPrice)) {
                $minPrice = $price;
            }
            $avail = $v['availableForSale'] ?? $v['available'] ?? $v['inStock'] ?? true;
            $variants[] = [
                'id' => $v['id'] ?? null, 'title' => implode(' / ', array_values($opts)), 'options' => $opts,
                'price' => $price, 'was' => $onSale ? $compare : null, 'on_sale' => (bool) $onSale,
                'available' => (bool) $avail,
            ];
        }
        if (! $variants) {
            return null;
        }

        return [
            'title' => $node['title'] ?? null,
            'description' => isset($node['description']) ? trim(strip_tags((string) $node['description'])) : null,
            'images' => $this->storefrontImages($node),
            'price' => $minPrice, 'was' => $anySale ? $minCompare : null, 'on_sale' => $anySale,
            'options' => $options, 'variants' => $variants,
        ];
    }

    /** Money that may be a scalar or a {amount} object. */
    private function moneyField($v): ?float
    {
        if (is_array($v)) {
            $v = $v['amount'] ?? $v['value'] ?? null;
        }
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (float) $v : $this->money($v);
    }

    /** Image URLs from a storefront product node (images/media, various shapes). */
    private function storefrontImages(array $node): array
    {
        $out = [];
        foreach (['images', 'media'] as $key) {
            $arr = $node[$key] ?? null;
            if (! is_array($arr)) {
                continue;
            }
            foreach ($arr as $img) {
                $u = is_string($img)
                    ? $img
                    : ($img['src'] ?? $img['url'] ?? $img['originalSrc'] ?? ($img['image']['url'] ?? null));
                if (is_string($u) && str_starts_with($u, 'http')) {
                    $out[] = $u;
                }
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 10);
    }

    /**
     * New Balance (Salesforce Commerce) embeds its full SKU matrix as
     * [{id,style,size,width}]. Static stock isn't exposed (loaded via AJAX), so
     * we surface the real Talla (+ Ancho when there's a choice) for the current
     * colorway — far better than a free-text box; Boxly confirms stock at buy.
     * Adapts to apparel too (no width → Talla only).
     */
    private function nbVariants(string $html, string $url): ?array
    {
        if (! preg_match('~\[\{"id":"[A-Za-z0-9-]+","style":"[A-Za-z0-9]+","size":"[^"]+","width":"[^"]*"\}.*?\]~s', $html, $m)) {
            return null;
        }
        $arr = json_decode($m[0], true);
        if (! is_array($arr) || ! $arr) {
            return null;
        }

        // Current colorway = the style code in the product URL (…/WL574EVG-D-07.html).
        $style = null;
        if (preg_match('~/([A-Za-z]{1,4}[0-9]{2,}[A-Za-z0-9]*)-[A-Za-z0-9]+-[0-9]+\.html~', $url, $um)) {
            $style = strtoupper($um[1]);
        }
        $items = $style ? array_values(array_filter($arr, fn ($v) => strtoupper((string) ($v['style'] ?? '')) === $style)) : [];
        if (! $items) {
            $items = $arr;
        }

        $sizes = [];
        $widths = [];
        foreach ($items as $v) {
            $s = (string) ($v['size'] ?? '');
            $w = (string) ($v['width'] ?? '');
            if ($s !== '') {
                $sizes[$s] = true;
            }
            if ($w !== '') {
                $widths[$w] = true;
            }
        }
        if (! $sizes) {
            return null;
        }

        $sizeVals = array_keys($sizes);
        usort($sizeVals, fn ($a, $b) => (is_numeric($a) && is_numeric($b)) ? ((float) $a <=> (float) $b) : strcmp((string) $a, (string) $b));
        $widthVals = array_keys($widths);
        $hasWidth = count($widthVals) > 1; // only show width when it's an actual choice

        $options = [['name' => 'Talla', 'values' => $sizeVals]];
        if ($hasWidth) {
            $options[] = ['name' => 'Ancho', 'values' => $widthVals];
        }

        $variants = [];
        foreach ($items as $v) {
            $s = (string) ($v['size'] ?? '');
            $w = (string) ($v['width'] ?? '');
            if ($s === '') {
                continue;
            }
            $opts = ['Talla' => $s];
            if ($hasWidth && $w !== '') {
                $opts['Ancho'] = $w;
            }
            $variants[] = [
                'id'        => $v['id'] ?? null,
                'title'     => trim($s . ($hasWidth && $w !== '' ? ' / ' . $w : '')),
                'options'   => $opts,
                'price'     => null, // base price comes from JSON-LD / the card
                'was'       => null,
                'on_sale'   => false,
                'available' => true, // NB hides static stock; Boxly verifies at purchase
            ];
        }
        if (! $variants) {
            return null;
        }

        return [
            'title' => null, 'description' => null, 'images' => [],
            'price' => null, 'was' => null, 'on_sale' => false,
            'options' => $options, 'variants' => $variants,
        ];
    }

    /**
     * JSON-LD ProductGroup/Product with multiple offers → an option list with
     * per-offer price + availability (covers many custom/brand stores).
     */
    private function jsonLdVariants(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $block) {
            $json = json_decode(trim($block), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($this->flattenJsonLd($json) as $node) {
                $type = $node['@type'] ?? null;
                $isProduct = $type === 'Product' || $type === 'ProductGroup'
                    || (is_array($type) && (in_array('Product', $type, true) || in_array('ProductGroup', $type, true)));
                if (! $isProduct) {
                    continue;
                }

                // ProductGroup: each hasVariant is a Product with its own offer.
                $variantsRaw = $node['hasVariant'] ?? null;
                if (is_array($variantsRaw) && $variantsRaw) {
                    $r = $this->variantsFromNodes($variantsRaw);
                    if ($r) {
                        return $r;
                    }
                }

                // Product with an array of offers (often per size).
                $offers = $node['offers'] ?? null;
                if (is_array($offers) && array_is_list($offers) && count($offers) > 1) {
                    $values = [];
                    $variants = [];
                    $minP = null;
                    foreach ($offers as $o) {
                        if (! is_array($o)) {
                            continue;
                        }
                        $label = $o['name'] ?? $o['sku'] ?? null;
                        $price = isset($o['price']) ? (float) $o['price'] : ($o['lowPrice'] ?? null);
                        $avail = stripos((string) ($o['availability'] ?? ''), 'InStock') !== false
                            || ($o['availability'] ?? '') === '';
                        if ($price !== null && ($minP === null || $price < $minP)) {
                            $minP = $price;
                        }
                        if ($label) {
                            $values[] = (string) $label;
                            $variants[] = [
                                'id' => $o['sku'] ?? null, 'title' => (string) $label,
                                'options' => ['Opción' => (string) $label],
                                'price' => $price, 'was' => null, 'on_sale' => false,
                                'available' => $avail,
                            ];
                        }
                    }
                    if ($variants) {
                        return [
                            'title' => $node['name'] ?? null, 'description' => $node['description'] ?? null, 'images' => [],
                            'price' => $minP, 'was' => null, 'on_sale' => false,
                            'options' => [['name' => 'Opción', 'values' => array_values(array_unique($values))]],
                            'variants' => $variants,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /** Build a single-option matrix from a list of variant Product nodes. */
    private function variantsFromNodes(array $nodes): ?array
    {
        $variants = [];
        $values = [];
        $minP = null;
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $offer = $n['offers'] ?? null;
            if (is_array($offer) && array_is_list($offer)) {
                $offer = $offer[0] ?? null;
            }
            $price = isset($offer['price']) ? (float) $offer['price'] : null;
            $avail = stripos((string) ($offer['availability'] ?? ''), 'InStock') !== false || ($offer['availability'] ?? '') === '';
            $label = $n['name'] ?? $n['sku'] ?? null;
            if (! $label) {
                continue;
            }
            if ($price !== null && ($minP === null || $price < $minP)) {
                $minP = $price;
            }
            $values[] = (string) $label;
            $variants[] = [
                'id' => $n['sku'] ?? null, 'title' => (string) $label,
                'options' => ['Opción' => (string) $label],
                'price' => $price, 'was' => null, 'on_sale' => false, 'available' => $avail,
            ];
        }
        if (! $variants) {
            return null;
        }

        return [
            'title' => null, 'description' => null, 'images' => [],
            'price' => $minP, 'was' => null, 'on_sale' => false,
            'options' => [['name' => 'Opción', 'values' => array_values(array_unique($values))]],
            'variants' => $variants,
        ];
    }

    /** Collect product images from og:image tags and JSON-LD image arrays. */
    private function gatherImages(string $html): array
    {
        $images = [];
        if (preg_match_all('~<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $u) {
                if (str_starts_with($u, 'http')) {
                    $images[] = $u;
                }
            }
        }
        return array_values(array_unique($images));
    }

    /** Merge two detail arrays, preferring non-empty values from $b. */
    private function mergeDetail(array $a, array $b): array
    {
        foreach (['title', 'price', 'was', 'description'] as $k) {
            if (($a[$k] ?? null) === null || ($a[$k] ?? '') === '') {
                $a[$k] = $b[$k] ?? ($a[$k] ?? null);
            }
        }
        if (! empty($b['on_sale'])) {
            $a['on_sale'] = true;
        }
        foreach (['options', 'variants', 'images'] as $k) {
            if (empty($a[$k]) && ! empty($b[$k])) {
                $a[$k] = $b[$k];
            }
        }

        return $a;
    }

    private function money($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = str_replace(',', '', (string) $v);
        if (preg_match('/([0-9]+(?:\.[0-9]{1,2})?)/', $s, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function optionLabel(string $raw): string
    {
        $clean = ucwords(trim(str_replace(['_', '-'], ' ', $raw)));
        $map = [
            'Color' => 'Color', 'Colour' => 'Color', 'Size' => 'Talla', 'Sizes' => 'Talla',
            'Material Type' => 'Material', 'Style' => 'Estilo', 'Style Name' => 'Estilo',
            'Pattern' => 'Diseño', 'Pa Color' => 'Color', 'Pa Size' => 'Talla',
            'Length' => 'Largo', 'Inseam' => 'Largo',
        ];

        return $map[$clean] ?? ($clean ?: 'Opción');
    }

    private function isGoogleLink(?string $u): bool
    {
        return is_string($u) && (str_contains($u, 'google.com') || str_contains($u, 'gstatic.com'));
    }

    /** SerpAPI immersive product → images, description, direct seller link. */
    private function immersive(string $token): ?array
    {
        $key = config('services.serpapi.key');
        if (! $key) {
            return null;
        }
        try {
            $res = Http::timeout(18)->get('https://serpapi.com/search.json', [
                'engine' => 'google_immersive_product', 'page_token' => $token, 'api_key' => $key,
            ]);
            if (! $res->successful()) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $pr = $res->json('product_results') ?? [];
        $images = array_values(array_filter(
            array_map(fn ($t) => is_array($t) ? ($t['link'] ?? null) : $t, $pr['thumbnails'] ?? []),
            fn ($v) => is_string($v) && str_starts_with($v, 'http')
        ));
        $link = null;
        foreach ($pr['stores'] ?? [] as $s) {
            if (! empty($s['link'])) { $link = $s['link']; break; }
        }

        return ['images' => array_slice($images, 0, 8), 'description' => $pr['about_the_product']['description'] ?? null, 'link' => $link];
    }

    /**
     * Live Shopify variant data via <product-url>.js — every size/option with its
     * price, compare_at (sale) price and in-stock flag.
     */
    private function shopifyVariants(string $url): ?array
    {
        if (! preg_match('~^(https?://[^/]+/(?:.*/)?products/[^/?#]+)~', $url, $m)) {
            return null;
        }
        $jsUrl = $m[1] . '.js';
        $key = config('services.scraperapi.key');
        try {
            $target = $key
                ? 'https://api.scraperapi.com?' . http_build_query(['api_key' => $key, 'url' => $jsUrl, 'country_code' => 'us'])
                : $jsUrl;
            $res = Http::timeout(30)->get($target);
            if (! $res->successful()) {
                return null;
            }
            $data = $res->json();
            if (! is_array($data) || empty($data['title'])) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $optionNames = array_map(fn ($o) => is_array($o) ? ($o['name'] ?? 'Opción') : $o, $data['options'] ?? []);

        $variants = [];
        $minPrice = null;
        $minCompare = null;
        $anyOnSale = false;
        foreach ($data['variants'] ?? [] as $v) {
            $price = isset($v['price']) ? round(((float) $v['price']) / 100, 2) : null;
            $compareRaw = $v['compare_at_price'] ?? null;
            $compare = $compareRaw ? round(((float) $compareRaw) / 100, 2) : null;
            $onSale = $compare && $price && $compare > $price;
            if ($onSale) {
                $anyOnSale = true;
                if ($minCompare === null || $compare < $minCompare) $minCompare = $compare;
            }
            if ($price !== null && ($minPrice === null || $price < $minPrice)) $minPrice = $price;

            $opts = [];
            foreach ([1, 2, 3] as $i) {
                $name = $optionNames[$i - 1] ?? null;
                $val = $v['option' . $i] ?? null;
                if ($name && $val !== null && $val !== '') $opts[$name] = $val;
            }
            $variants[] = [
                'id'        => $v['id'] ?? null,
                'title'     => $v['title'] ?? implode(' / ', array_values($opts)),
                'options'   => $opts,
                'price'     => $price,
                'was'       => $onSale ? $compare : null,
                'on_sale'   => $onSale,
                'available' => (bool) ($v['available'] ?? false),
            ];
        }

        // Distinct values per option, preserving order.
        $optionList = [];
        foreach ($optionNames as $name) {
            $vals = [];
            foreach ($variants as $v) {
                $val = $v['options'][$name] ?? null;
                if ($val !== null && ! in_array($val, $vals, true)) $vals[] = $val;
            }
            if ($vals) $optionList[] = ['name' => $name, 'values' => $vals];
        }

        $images = array_map(
            fn ($img) => str_starts_with((string) $img, 'http') ? $img : 'https:' . $img,
            $data['images'] ?? []
        );

        return [
            'title'    => $data['title'],
            'price'    => $minPrice,
            'was'      => $anyOnSale ? $minCompare : null,
            'on_sale'  => $anyOnSale,
            'options'  => $optionList,
            'variants' => $variants,
            'images'   => array_slice(array_values(array_filter($images)), 0, 10),
        ];
    }

    /**
     * Run several Google Shopping queries (general + priority retailers) in
     * PARALLEL via SerpAPI, with a per-query 30-min cache. Returns a map of
     * query => products[]. Lets us check the stores customers actually shop
     * (Target, Walmart, Dick's…) without serializing the latency or re-billing.
     */
    private function multiShopping(array $queries, int $start = 0): array
    {
        $key = config('services.serpapi.key');
        $location = config('services.serpapi.location');
        $out = [];
        $toFetch = []; // query => cacheKey

        foreach (array_values(array_unique($queries)) as $q) {
            $cacheKey = 'serp_shop:' . md5($q . '|' . $location) . ':' . $start;
            $hit = Cache::get($cacheKey);
            if ($hit !== null) {
                $out[$q] = $hit;
            } else {
                $toFetch[$q] = $cacheKey;
            }
        }

        if ($key && $toFetch) {
            try {
                $responses = Http::pool(function ($pool) use ($toFetch, $key, $location, $start) {
                    $reqs = [];
                    foreach ($toFetch as $q => $cacheKey) {
                        $params = [
                            'engine' => 'google_shopping', 'q' => $q, 'gl' => 'us', 'hl' => 'en',
                            'num' => 40, 'start' => $start, 'api_key' => $key,
                        ];
                        if (! empty($location)) {
                            $params['location'] = $location;
                        }
                        $reqs[] = $pool->as($q)->timeout(35)->get('https://serpapi.com/search.json', $params);
                    }

                    return $reqs;
                });
            } catch (\Throwable $e) {
                $responses = [];
            }
            foreach ($toFetch as $q => $cacheKey) {
                $resp = $responses[$q] ?? null;
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $products = $this->parseShoppingResults($resp->json());
                    Cache::put($cacheKey, $products, now()->addMinutes(30));
                    $out[$q] = $products;
                } else {
                    $out[$q] = [];
                }
            }
        }

        return $out;
    }

    /** Normalize a SerpAPI google_shopping response into our product shape. */
    private function parseShoppingResults($json): array
    {
        $results = is_array($json) ? ($json['shopping_results'] ?? null) : null;
        if (! is_array($results)) {
            return [];
        }
        $products = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            if (! $title) {
                continue;
            }
            $price = $r['extracted_price'] ?? null;
            if ($price === null && ! empty($r['price'])) {
                $price = (float) preg_replace('/[^0-9.]/', '', (string) $r['price']);
            }
            $old = $r['extracted_old_price'] ?? null;
            $onSale = $old && $price && $old > $price;
            $products[] = [
                'title'   => $title,
                'price'   => $price ?: null,
                'was'     => $onSale ? $old : null,
                'on_sale' => $onSale,
                'store'   => $r['source'] ?? null,
                'image'   => $r['serpapi_thumbnail'] ?? $r['thumbnail'] ?? null,
                'url'     => $r['product_link'] ?? ('https://www.google.com/search?tbm=shop&q=' . urlencode($title)),
                'snippet' => $r['snippet'] ?? null,
                'rating'  => $r['rating'] ?? null,
                'reviews' => $r['reviews'] ?? null,
                'token'   => $r['immersive_product_page_token'] ?? null,
            ];
        }

        return $products;
    }

    /**
     * Priority retailers to also check for a query, by category — the big-box
     * stores customers hunt deals in for name brands. Capped to keep the search
     * fast/cheap; skipped when the user already fixed a store.
     */
    private function priorityRetailers(string $query): array
    {
        $q = mb_strtolower($query);
        $has = fn (array $kw) => (bool) array_filter($kw, fn ($k) => str_contains($q, $k));

        $sets = [
            [['shoe', 'sneaker', 'running', 'jordan', 'nike', 'adidas', 'cleat', 'jersey', 'gym', 'fitness', 'dumbbell', 'yoga', 'golf', 'basketball', 'soccer', 'athletic', 'workout', 'hike', 'camp'],
                ["Dick's Sporting Goods", 'Walmart', 'Target']],
            [['tv', 'laptop', 'headphone', 'airpods', 'console', 'playstation', 'xbox', 'nintendo', 'camera', 'tablet', 'ipad', 'monitor', 'gaming', 'speaker', 'phone', 'earbuds'],
                ['Best Buy', 'Walmart', 'Target']],
            [['makeup', 'skincare', 'perfume', 'cologne', 'beauty', 'lipstick', 'foundation', 'fragrance', 'serum', 'mascara'],
                ['Ulta', 'Sephora', 'Target']],
            [['kitchen', 'furniture', 'decor', 'bedding', 'vacuum', 'sofa', 'mattress', 'cookware', 'home'],
                ['Target', 'Walmart', 'Wayfair']],
            [['toy', 'lego', 'doll', 'kids', 'baby', 'stroller', 'diaper'],
                ['Target', 'Walmart', 'Amazon']],
            [['dress', 'shirt', 'jeans', 'jacket', 'clothing', 'hoodie', 'sweater', 'coat', 'pants', 'apparel', 'leggings', 'shorts'],
                ['Target', "Macy's", 'Nordstrom Rack']],
        ];
        foreach ($sets as [$kw, $retailers]) {
            if ($has($kw)) {
                return array_slice($retailers, 0, 3);
            }
        }

        // Default big-box deal stores for anything else.
        return ['Target', 'Walmart'];
    }

    // Big-box / department / major retailers — most trustworthy, shown first.
    private const TRUSTED_BIGBOX = [
        'target', 'walmart', "dick's", 'dicks sporting', 'best buy', 'costco', "sam's club",
        "macy's", 'macys', 'nordstrom', "kohl's", 'kohls', 'rei', 'amazon', 'ulta', 'sephora',
        'home depot', "lowe's", 'lowes', 'academy', 'foot locker', 'jcpenney', 'jc penney',
        "dillard's", 'dillards', 'belk', 'wayfair', 'bloomingdale', 'saks', 'gamestop',
        'barnes & noble', 'walgreens', 'cvs', 'petco', 'petsmart', 'staples', 'office depot',
        'zappos', 'qvc', 'overstock', 'newegg', 'michaels', 'apple',
    ];

    // Recognized brand stores / well-known names — trusted, just below big-box.
    private const TRUSTED_BRANDS = [
        'nike', 'adidas', 'new balance', 'under armour', 'lululemon', 'columbia',
        'the north face', 'patagonia', 'gap', 'old navy', 'vans', 'converse', 'crocs',
        'puma', 'reebok', 'skechers', 'hoka', 'asics', 'on running', 'gymshark', 'alo',
        'abercrombie', 'hollister', 'american eagle', 'urban outfitters', 'pacsun', 'zara',
        'h&m', 'uniqlo', 'coach', 'michael kors', 'kate spade', 'ralph lauren', 'tommy hilfiger',
        'calvin klein', 'levi', 'stanley', 'owala', 'hydro flask', 'yeti', 'samsung', 'lego',
        'disney', 'pokemon center', "victoria's secret", 'bath & body works', 'fanatics',
    ];

    /**
     * Trustworthiness score for a result's store, used to rank big, recognizable
     * sellers ahead of tiny niche resellers. 2 = big-box/department, 1 = known
     * brand, 0 = niche/unknown. Substring match handles "Walmart - SellerX" etc.
     */
    private function storeTrust(?string $store): int
    {
        $s = mb_strtolower(trim((string) $store));
        if ($s === '') {
            return 0;
        }
        foreach (self::TRUSTED_BIGBOX as $kw) {
            if (str_contains($s, $kw)) {
                return 2;
            }
        }
        foreach (self::TRUSTED_BRANDS as $kw) {
            if (str_contains($s, $kw)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Merge results keeping relevance: lead with the general (Google-ranked)
     * list (2 per round so it stays dominant), weaving one item from each
     * priority-store list per round.
     */
    private function interleaveResults(array $general, array $priorityLists): array
    {
        $out = [];
        $gi = 0;
        $idx = array_fill(0, count($priorityLists), 0);
        $progress = true;
        while ($progress) {
            $progress = false;
            for ($k = 0; $k < 2; $k++) {
                if ($gi < count($general)) {
                    $out[] = $general[$gi++];
                    $progress = true;
                }
            }
            foreach ($priorityLists as $i => $list) {
                if ($idx[$i] < count($list)) {
                    $out[] = $list[$idx[$i]++];
                    $progress = true;
                }
            }
        }

        return $out;
    }

    /** Dedupe products, keeping the same product at DIFFERENT stores (price compare). */
    private function dedupeProducts(array $products): array
    {
        $seen = [];
        $out = [];
        foreach ($products as $p) {
            $url = $p['url'] ?? '';
            $key = ($url && ! str_contains((string) $url, 'google.com/search'))
                ? 'u:' . $url
                : 't:' . mb_strtolower(trim((string) ($p['title'] ?? ''))) . '|' . mb_strtolower((string) ($p['store'] ?? ''));
            if ($key === 't:|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $p;
        }

        return $out;
    }

    private function slugify(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }

    /** Fallback: ScraperAPI structured Google Shopping. Null on failure. */
    private function shoppingViaScraperapi(string $q): ?array
    {
        $key = config('services.scraperapi.key');
        if (! $key) {
            return null;
        }
        try {
            // Google needs advanced/residential proxies — plain/premium 500
            // intermittently; ultra_premium is reliable but slower (~20-30s).
            $res = Http::timeout(40)->get('https://api.scraperapi.com/structured/google/shopping', [
                'api_key'       => $key,
                'query'         => $q,
                'country'       => 'us',
                'ultra_premium' => 'true',
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $res->successful()) {
            return null;
        }

        $results = $res->json('shopping_results');
        if (! is_array($results)) {
            return null;
        }

        $products = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            if (! $title) {
                continue;
            }
            $price = $r['extracted_price'] ?? null;
            if ($price === null && ! empty($r['price'])) {
                $price = (float) preg_replace('/[^0-9.]/', '', (string) $r['price']);
            }
            $img = $r['thumbnail'] ?? null;
            if ($img && ! Str::startsWith($img, ['data:', 'http'])) {
                $img = null;
            }
            $products[] = [
                'title' => $title,
                'price' => $price ?: null,
                'store' => $r['source'] ?? null,
                'image' => $img,
                'url'   => 'https://www.google.com/search?tbm=shop&q=' . urlencode($title),
            ];
        }
        return $products;
    }

    private function fetch(string $url, bool $render = false): ?string
    {
        $key = config('services.scraperapi.key');
        try {
            if ($key) {
                $res = Http::timeout($render ? 60 : 12)->get('https://api.scraperapi.com', [
                    'api_key' => $key,
                    'url' => $url,
                    'render' => $render ? 'true' : 'false',
                    'country_code' => 'us',
                ]);
            } else {
                // No ScraperAPI key (e.g. local) — try a direct fetch.
                $res = Http::timeout(20)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BoxlyBot/1.0)',
                ])->get($url);
            }
            return $res->successful() ? $res->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Shopify stores expose <product-url>.js with clean JSON. */
    private function parseShopify(string $url): ?array
    {
        if (! preg_match('~^(https?://[^/]+/(?:.*/)?products/[^/?#]+)~', $url, $m)) {
            return null;
        }
        $key = config('services.scraperapi.key');
        $jsUrl = $m[1] . '.js';
        try {
            $target = $key
                ? 'https://api.scraperapi.com?' . http_build_query(['api_key' => $key, 'url' => $jsUrl, 'country_code' => 'us'])
                : $jsUrl;
            $res = Http::timeout(30)->get($target);
            if (! $res->successful()) {
                return null;
            }
            $data = $res->json();
            if (! is_array($data) || empty($data['title'])) {
                return null;
            }
            $cents = $data['price'] ?? ($data['variants'][0]['price'] ?? null);
            return [
                'title' => $data['title'],
                'price' => $cents !== null ? round($cents / 100, 2) : null,
                'image' => isset($data['featured_image'])
                    ? (Str::startsWith($data['featured_image'], 'http') ? $data['featured_image'] : 'https:' . $data['featured_image'])
                    : ($data['images'][0] ?? null),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** schema.org Product JSON-LD — works on most non-Shopify stores. */
    private function parseJsonLd(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $block) {
            $json = json_decode(trim($block), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($this->flattenJsonLd($json) as $node) {
                $type = $node['@type'] ?? null;
                $isProduct = $type === 'Product' || (is_array($type) && in_array('Product', $type, true));
                if (! $isProduct) {
                    continue;
                }
                $offers = $node['offers'] ?? null;
                if (is_array($offers) && array_is_list($offers)) {
                    $offers = $offers[0] ?? null;
                }
                $price = $offers['price'] ?? ($offers['lowPrice'] ?? null);
                $image = $node['image'] ?? null;
                if (is_array($image)) {
                    $image = $image[0] ?? null;
                }
                if (is_array($image)) {
                    $image = $image['url'] ?? null;
                }
                return [
                    'title' => is_array($node['name'] ?? null) ? ($node['name'][0] ?? null) : ($node['name'] ?? null),
                    'price' => $price !== null ? (float) $price : null,
                    'image' => $image,
                ];
            }
        }
        return null;
    }

    /** Last resort: OpenGraph / meta tags. */
    private function parseMeta(string $html): ?array
    {
        $title = $this->meta($html, 'og:title') ?? $this->meta($html, 'twitter:title');
        if (! $title) {
            return null;
        }
        $price = $this->meta($html, 'product:price:amount') ?? $this->meta($html, 'og:price:amount');
        return [
            'title' => $title,
            'price' => $price !== null ? (float) $price : null,
            'image' => $this->meta($html, 'og:image'),
        ];
    }

    /**
     * Last-resort price recovery: scan the raw HTML for an embedded schema.org
     * Offer (works when the price lives in an app-state/remix blob instead of
     * the Product JSON-LD, as on headless Shopify stores like Gymshark).
     */
    private function priceFromHtml(string $html): ?float
    {
        // "priceCurrency":"USD","price":40  (or price before currency)
        if (preg_match('/"priceCurrency"\s*:\s*"[A-Z]{3}"\s*,\s*"price"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)/i', $html, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/"price"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)"?\s*,\s*"priceCurrency"\s*:\s*"[A-Z]{3}"/i', $html, $m)) {
            return (float) $m[1];
        }
        // Common meta fallbacks.
        foreach (['product:price:amount', 'og:price:amount', 'twitter:data1'] as $prop) {
            $val = $this->meta($html, $prop);
            if ($val && preg_match('/([0-9]+(?:\.[0-9]{1,2})?)/', $val, $m)) {
                return (float) $m[1];
            }
        }
        return null;
    }

    private function meta(string $html, string $prop): ?string
    {
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '#') . '["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            return html_entity_decode($m[1]);
        }
        return null;
    }

    /** JSON-LD can be a single node, a list, or wrapped in @graph. */
    private function flattenJsonLd(array $json): array
    {
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            return $json['@graph'];
        }
        return array_is_list($json) ? $json : [$json];
    }

    private function storeFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host);
        return $host;
    }

    /**
     * Pull a real product feed from a (Shopify) US store — the latest drop, or a
     * keyword search within the store. Powers the assistant's browse_store tool.
     * Returns [] for non-Shopify stores (assistant falls back to web search).
     */
    public function storeFeed(Request $request)
    {
        $validated = $request->validate([
            'url'   => 'required|url|max:2000',
            'query' => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:24',
            'sale'  => 'nullable|boolean',
        ]);

        $origin = $this->origin($validated['url']);
        if (! $origin) {
            return response()->json(['success' => false, 'message' => 'Invalid store URL.'], 422);
        }

        $limit = $validated['limit'] ?? 12;

        // NOTE: the `sale` flag is intentionally NOT a hard filter. Stores like
        // YoungLA rarely discount, so sale-only would return a lonely 1-item
        // gallery. Instead we ALWAYS return a lively hybrid (deals + the rest of
        // the catalog) with the deals sorted first — a much better experience.
        if (! empty($validated['query'])) {
            $products = $this->shopifySearch($origin, $validated['query'], $limit);
        } else {
            // Wider window so on-sale items deeper in the catalog can surface,
            // not just whatever's in the newest handful.
            $products = $this->shopifyProducts($origin, $limit * 4);
        }

        // Deals first (stable — keeps the rest of the catalog in normal order),
        // then trim to the requested limit.
        usort($products, fn ($a, $b) => (empty($a['on_sale']) ? 1 : 0) <=> (empty($b['on_sale']) ? 1 : 0));
        $products = array_slice($products, 0, $limit);

        return response()->json([
            'success' => true,
            'data'    => ['store' => $this->storeFromUrl($validated['url']), 'products' => $products],
        ]);
    }

    private function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        return $p['scheme'] . '://' . $p['host'];
    }

    /**
     * Latest products via Shopify products.json (sorted newest first). When
     * $onlySale is set, returns only items whose compare_at_price beats the
     * current price (i.e. real deals).
     */
    private function shopifyProducts(string $origin, int $limit, bool $onlySale = false): array
    {
        // Pull a wider window when hunting for deals — sale items are sparse.
        $fetch = $onlySale ? min($limit * 5, 150) : min($limit * 2, 50);
        $body = $this->fetch($origin . '/products.json?limit=' . $fetch);
        if (! $body) {
            return [];
        }
        $items = json_decode($body, true)['products'] ?? null;
        if (! is_array($items)) {
            return [];
        }
        usort($items, fn ($a, $b) => strcmp(
            (string) ($b['published_at'] ?? $b['created_at'] ?? ''),
            (string) ($a['published_at'] ?? $a['created_at'] ?? '')
        ));

        $out = [];
        foreach ($items as $p) {
            $variant = $p['variants'][0] ?? null;
            $price   = isset($variant['price']) ? (float) $variant['price'] : null;
            $compare = isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null;
            $onSale  = $compare && $price && $compare > $price;

            if ($onlySale && ! $onSale) {
                continue;
            }

            $out[] = [
                'title'   => $p['title'] ?? null,
                'price'   => $price,
                'was'     => $onSale ? $compare : null,
                'on_sale' => $onSale,
                'image'   => $p['images'][0]['src'] ?? null,
                'url'     => $origin . '/products/' . ($p['handle'] ?? ''),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Keyword search within a Shopify store via predictive search. */
    private function shopifySearch(string $origin, string $query, int $limit): array
    {
        $url = $origin . '/search/suggest.json?' . http_build_query([
            'q'                 => $query,
            'resources[type]'   => 'product',
            'resources[limit]'  => min($limit, 10),
        ]);
        $body = $this->fetch($url);
        if (! $body) {
            return [];
        }
        $items = json_decode($body, true)['resources']['results']['products'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach (array_slice($items, 0, $limit) as $p) {
            $raw = $p['price'] ?? null;
            $price = $raw !== null ? (float) preg_replace('/[^0-9.]/', '', (string) $raw) : null;
            // Predictive search sometimes returns the price in cents.
            if ($price && $price >= 1000 && strpos((string) $raw, '.') === false) {
                $price = $price / 100;
            }
            // Best-effort sale detection (predictive search may include it).
            $cmpRaw = $p['compare_at_price'] ?? null;
            $compare = $cmpRaw !== null ? (float) preg_replace('/[^0-9.]/', '', (string) $cmpRaw) : null;
            if ($compare && $compare >= 1000 && strpos((string) $cmpRaw, '.') === false) {
                $compare = $compare / 100;
            }
            $onSale = $compare && $price && $compare > $price;
            $u = $p['url'] ?? null;
            if ($u && ! str_starts_with($u, 'http')) {
                $u = $origin . $u;
            }
            $out[] = [
                'title'   => $p['title'] ?? null,
                'price'   => $price ?: null,
                'was'     => $onSale ? $compare : null,
                'on_sale' => $onSale,
                'image'   => $p['image'] ?? ($p['featured_image']['url'] ?? null),
                'url'     => $u,
            ];
        }
        return $out;
    }
}
