<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

// The AI search's product source. The catalog itself lives outside Laravel — a
// standalone service on the fullstack domain that serves products harvested from
// our favorite stores by the computer-use agents. This endpoint is the app's
// gateway to it (app → Boxly API → catalog API), returning SERP-shaped products.
class CatalogController extends Controller
{
    public function search(Request $request)
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return response()->json(['query' => '', 'count' => 0, 'products' => [], 'error' => 'catalog_not_configured'], 200);
        }
        // Forward the full structured-filter set the AI drives the catalog with.
        // The catalog does the fuzzy store resolution, forgiving match and sorting;
        // here we just pass every filter through (dropping empties).
        $params = array_filter([
            'q' => $request->query('q') ?: $request->query('query'),
            'store' => $request->query('store'),
            'brands' => $request->query('brands'),                                 // comma-separated
            'category' => $request->query('category'),
            'sale' => $request->boolean('sale') ? '1' : null,
            'min' => $request->query('min') ?: $request->query('min_price'),
            'max' => $request->query('max') ?: $request->query('max_price'),
            'min_discount' => $request->query('min_discount'),
            'sort' => $request->query('sort'),
            'limit' => $request->query('limit', 16),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $res = Http::timeout(10)->acceptJson()->get("{$base}/catalog/search", $params);
        } catch (\Throwable $e) {
            return response()->json(['query' => (string) ($params['q'] ?? ''), 'count' => 0, 'products' => [], 'error' => 'catalog_unreachable'], 200);
        }
        if (! $res->ok()) {
            return response()->json(['query' => (string) ($params['q'] ?? ''), 'count' => 0, 'products' => [], 'error' => 'catalog_error'], 200);
        }
        $data = $res->json();
        return response()->json([
            'query' => $data['query'] ?? ($params['q'] ?? ''),
            'count' => $data['count'] ?? count($data['products'] ?? []),
            // resolved = how store/brand inputs mapped (e.g. a typo'd store name).
            'resolved' => $data['resolved'] ?? null,
            // Miss signals the assistant acts on (they were being dropped here, so the app
            // never saw a search miss): no_exact_match / missing_terms = a named model is in
            // none of the rows; query_matched=false = nothing matched the shopper's words.
            'no_exact_match' => (bool) ($data['no_exact_match'] ?? false),
            'missing_terms' => $data['missing_terms'] ?? [],
            'query_matched' => $data['query_matched'] ?? true,
            // relaxed = a named store's facets (sale / price / category) matched nothing, so
            // the catalog dropped them (relaxed_filters says which) rather than return empty.
            'relaxed' => (bool) ($data['relaxed'] ?? false),
            'relaxed_filters' => $data['relaxed_filters'] ?? [],
            'products' => $data['products'] ?? [],
        ]);
    }

    // Curate: the dynamic "showing" over the catalog's understanding layer. The AI POSTs
    // a structured intent (facets + a per-conversation seed + a seen list); the catalog
    // returns a personalized, VARIED, suspect-free selection led by the best deals. Fast
    // (a local SQL read) and never cached upstream so the rotation stays fresh.
    public function curate(Request $request)
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_not_configured'], 200);
        }
        // Pass the structured intent straight through (the catalog validates/defaults it).
        $body = $request->all();
        try {
            $res = Http::timeout(12)->acceptJson()->post("{$base}/catalog/curate", $body);
        } catch (\Throwable $e) {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_unreachable'], 200);
        }
        if (! $res->ok()) {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_error'], 200);
        }
        return response()->json($res->json());
    }

    // Collection: one curated editorial set (deal-driven or store-spotlight) by id. The AI
    // picks a collection from the conversation and POSTs its id + a per-conversation seed +
    // a seen list; the catalog returns that set's products, curated live. Fails soft.
    public function collection(Request $request)
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_not_configured'], 200);
        }
        $body = $request->all();
        try {
            $res = Http::timeout(12)->acceptJson()->post("{$base}/catalog/collection", $body);
        } catch (\Throwable $e) {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_unreachable'], 200);
        }
        if (! $res->ok()) {
            return response()->json(['count' => 0, 'products' => [], 'error' => 'catalog_error'], 200);
        }
        return response()->json($res->json());
    }

    // Live-grab: fetch a specific product the catalog doesn't have with the computer-use
    // agent (pasted link OR store+query). Heavy (~7-9s, spawns a headless browser) and
    // serialized upstream, so we allow a long timeout and fail soft — the assistant treats
    // an empty/errored result as "couldn't get it live" and offers another path.
    public function liveGrab(Request $request)
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return response()->json(['products' => [], 'error' => 'catalog_not_configured'], 200);
        }
        $body = array_filter([
            'url' => $request->input('url'),
            'store' => $request->input('store'),
            'query' => $request->input('query'),
        ], fn ($v) => $v !== null && $v !== '');
        if (! isset($body['url']) && ! (isset($body['store']) && isset($body['query']))) {
            return response()->json(['products' => [], 'error' => 'need url or store+query'], 200);
        }
        try {
            // 55s: the grab itself is capped at 45s upstream; allow headroom over that.
            $res = Http::timeout(55)->acceptJson()->post("{$base}/catalog/live-grab", $body);
        } catch (\Throwable $e) {
            return response()->json(['products' => [], 'error' => 'catalog_unreachable'], 200);
        }
        if (! $res->ok()) {
            return response()->json(['products' => [], 'error' => 'catalog_error'], 200);
        }
        // Pass the upstream result through as-is (product|products|error|blocked|note…).
        return response()->json($res->json());
    }

    // Product variants: sizes/colours with availability + price per variant for ONE product URL.
    // The moment a shopper commits to a product ("quiero esos"), the app goes straight to the
    // product's stored URL (catalog or live row) — no grid navigation — and asks the catalog
    // service: mirror if checked within max_age_s, else a live product-page read. Heavy path
    // (headless browser) upstream, so a long timeout; fails soft to {variants: []} + error.
    public function productVariants(Request $request)
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        if ($base === '') {
            return response()->json(['variants' => [], 'error' => 'catalog_not_configured'], 200);
        }
        $url = trim((string) $request->input('url'));
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return response()->json(['variants' => [], 'error' => 'need url'], 200);
        }
        $body = ['url' => $url];
        if ($request->filled('max_age_s')) {
            $body['max_age_s'] = max(0, (int) $request->input('max_age_s'));
        }
        try {
            $res = Http::timeout(55)->acceptJson()->post("{$base}/catalog/product-variants", $body);
        } catch (\Throwable $e) {
            return response()->json(['variants' => [], 'error' => 'catalog_unreachable'], 200);
        }
        if (! $res->ok()) {
            return response()->json(['variants' => [], 'error' => 'catalog_error'], 200);
        }
        return response()->json($res->json());
    }

    // Google Shopping search: the OUT-OF-CATALOG fallback. When we don't carry a product,
    // hit SerpAPI's google_shopping engine (US locale → USD prices, US merchants) for real
    // cross-web options with a title/price/merchant/image/link, which Boxly buys + delivers.
    // Fast (~1s, cached) and API-grade — no bot walls, unlike a headless-browser scrape.
    // Fails soft: no key / SerpAPI error → empty + a reason the assistant explains.
    public function googleShop(Request $request)
    {
        $query = trim((string) $request->input('query'));
        if ($query === '') {
            return response()->json(['products' => [], 'error' => 'need query'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['products' => [], 'error' => 'serpapi_not_configured'], 200);
        }
        $limit = $request->filled('limit') ? max(1, min(40, (int) $request->input('limit'))) : 16;
        // City-level location → simulates a shopper near the San Diego / San Ysidro warehouse
        // where goods actually land, so prices + availability match what Boxly will receive.
        $location = (string) config('services.serpapi.location');
        $cacheKey = 'gshop:' . md5(mb_strtolower($query) . '|' . $location);

        $products = Cache::get($cacheKey);
        if ($products === null) {
            // CIRCUIT BREAKER (2026-09-11): during SerpAPI's Google outage every call hung the full 15 s, and
            // with the assistant now firing Google beside every search those hangs pinned PHP-FPM workers
            // until even /catalog/search calls queued past their timeout. After one timeout, Google is
            // considered DOWN for 90 s and answers instantly with a retry hint; one probe re-tests it after.
            $downKey = 'gshop:down';
            $downUntil = Cache::get($downKey);
            if ($downUntil !== null && (int) $downUntil > time()) {
                return response()->json(['products' => [], 'error' => 'serpapi_unreachable', 'cooling' => true, 'retry_after_s' => max(1, (int) $downUntil - time())], 200);
            }
            $params = [
                'engine' => 'google_shopping', 'q' => $query, 'gl' => 'us', 'hl' => 'en',
                'num' => 40, 'api_key' => $key,
            ];
            if ($location !== '') {
                $params['location'] = $location;
            }
            $res = null;
            try {
                // 12 s. 8 s was right while Google was hard-down (fail fast, stop pinning PHP-FPM workers), but
                // SerpAPI now reports the engine operational and recovering — and a recovering engine answers
                // slower than the 1–3 s it takes when healthy, so an 8 s cap was cutting off good responses and
                // re-tripping the breaker. The breaker below is what protects the worker pool now, not the cap.
                $res = Http::timeout(12)->connectTimeout(4)->get('https://serpapi.com/search.json', $params);
            } catch (\Throwable $e) {
                $res = null; // a TIMEOUT throws — do not give up here, the lean retry below is the whole point
            }
            // SECOND CHANCE, LEANER. Every failing call hit the cap exactly, which is a request that hangs rather
            // than one that is slow. The two heaviest parameters are the city-level `location` (SerpAPI resolves
            // it server-side) and `num=40`. So when the full request times out, errors, or comes back empty, try
            // once as a plain national query before declaring Google down. My first version of this retry sat
            // INSIDE the try block after the call, so a timeout threw straight past it and it never ran.
            if ($res === null || ! $res->ok() || empty(data_get($res->json(), 'shopping_results'))) {
                try {
                    $lean = ['engine' => 'google_shopping', 'q' => $query, 'gl' => 'us', 'hl' => 'en', 'api_key' => $key];
                    $retry = Http::timeout(12)->connectTimeout(4)->get('https://serpapi.com/search.json', $lean);
                    if ($retry->ok() && ! empty(data_get($retry->json(), 'shopping_results'))) {
                        $res = $retry;
                    }
                } catch (\Throwable $e) { /* keep whatever the first attempt gave us */ }
            }
            if ($res === null) {
                Cache::put($downKey, time() + 90, now()->addSeconds(95));
                return response()->json(['products' => [], 'error' => 'serpapi_unreachable', 'cooling' => true, 'retry_after_s' => 90], 200);
            }
            Cache::forget($downKey);
            if (! $res->ok()) {
                return response()->json(['products' => [], 'error' => 'serpapi_error'], 200);
            }
            $products = $this->normalizeGoogleShopping($res->json());
            // Asymmetric TTL: a non-empty result is stable for 10 min; an empty one might be
            // SerpAPI flakiness for the same query, so re-probe soon (60s) — see the shopping
            // cache note in ProductExtractController.
            Cache::put($cacheKey, $products, now()->addSeconds($products ? 600 : 60));
        }

        // Never surface an imageless card (blank tile = broken), then cap to `limit`.
        $products = array_slice(array_values(array_filter($products, fn ($p) => ! empty($p['image']))), 0, $limit);
        if (empty($products)) {
            return response()->json(['products' => [], 'no_results' => true, 'source' => 'google'], 200);
        }
        return response()->json(['products' => $products, 'count' => count($products), 'source' => 'google']);
    }

    /** SerpAPI google_shopping response → our gallery product shape (title/price/was/on_sale/
     * discount/merchant/image/url/rating). Mirrors ProductExtractController::parseShoppingResults. */
    // Used / refurbished marketplaces and listings never reach the shopper (Alex, 2026-09-10): Boxly sells
    // NEW goods, and a Poshmark/Mercari card in a deals gallery reads as "they sell second-hand". Matched on
    // the merchant name, SerpAPI's own condition fields (second_hand_condition / tag) and the listing title.
    private const SECOND_HAND_MERCHANTS = [
        'poshmark', 'mercari', 'thredup', 'depop', 'vinted', 'grailed', 'offerup', 'facebook marketplace',
        'craigslist', 'swappa', 'back market', 'backmarket', 'gazelle', 'reebelo', 'the realreal', 'therealreal',
        'vestiaire', 'tradesy', 'kidizen', 'curtsy', 'goodwill', 'shopgoodwill', 'decluttr', 'letgo', '5miles',
        'rebag', 'fashionphile', 'stockx', 'goat',
    ];

    private static function isSecondHand(array $r, ?string $merchant, ?string $title): bool
    {
        $m = mb_strtolower((string) $merchant);
        foreach (self::SECOND_HAND_MERCHANTS as $bad) {
            if ($m !== '' && str_contains($m, $bad)) {
                return true;
            }
        }
        if (! empty($r['second_hand_condition'])) {
            return true;
        }
        $cond = mb_strtolower(trim(((string) ($r['tag'] ?? '')) . ' ' . ((string) $title)));
        return (bool) preg_match('/\b(used|pre-?owned|refurbished|refurb|renewed|open[- ]box|second[- ]hand|reconditioned)\b/u', $cond);
    }

    private function normalizeGoogleShopping($json): array
    {
        $results = is_array($json) ? ($json['shopping_results'] ?? null) : null;
        if (! is_array($results)) {
            return [];
        }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            if (! $title) {
                continue;
            }
            $price = $r['extracted_price'] ?? (isset($r['price']) ? (float) preg_replace('/[^0-9.]/', '', (string) $r['price']) : null);
            $old = $r['extracted_old_price'] ?? null;
            $onSale = $old && $price && $old > $price;
            $merchant = $r['source'] ?? null;
            if (self::isSecondHand($r, $merchant, $title)) {
                continue;
            }
            $out[] = [
                'title'        => $title,
                'price'        => $price ?: null,
                'was'          => $onSale ? $old : null,
                'on_sale'      => $onSale,
                'discount_pct' => $onSale ? (int) round(100 * ($old - $price) / $old) : null,
                'store'        => $merchant,
                'merchant'     => $merchant,
                'image'        => $r['serpapi_thumbnail'] ?? $r['thumbnail'] ?? null,
                'url'          => $r['product_link'] ?? $r['link'] ?? ('https://www.google.com/search?tbm=shop&q=' . urlencode($title)),
                'rating'       => $r['rating'] ?? null,
                'reviews'      => $r['reviews'] ?? null,
                'source'       => 'google',
            ];
        }

        return $out;
    }

    // ONE AMAZON PRODUCT, from its page — not the search row. Alex, 2026-09-11: "no matter what you search you
    // always get the product URL so the agent can still go to the product details page and fetch the variants, so
    // that step is NEVER skipped... even for a product with no variants the page still contains info and images we
    // need, and it might reveal the product is not available."
    //
    // A search row carries one image, no sizes and no stock. SerpAPI's `amazon_product` engine takes the ASIN out
    // of the product URL and returns the page: title, price, availability, the image gallery and the variant
    // dimensions. Same vendor and key as the search, so no new dependency and nothing scraped by us.
    public function amazonProduct(Request $request)
    {
        $asin = strtoupper(trim((string) $request->input('asin')));
        if ($asin === '') {
            // Accept a URL and pull the ASIN out of it — /dp/<ASIN>, /gp/product/<ASIN>, ?asin=<ASIN>.
            $url = (string) $request->input('url');
            if (preg_match('#/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})#i', $url, $m)) { $asin = strtoupper($m[1]); }
            elseif (preg_match('#[?&]asin=([A-Z0-9]{10})#i', $url, $m)) { $asin = strtoupper($m[1]); }
        }
        if (! preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            return response()->json(['variants' => [], 'axes' => [], 'error' => 'need asin'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_not_configured'], 200);
        }

        $cacheKey = 'amzprod:' . $asin;
        $payload = Cache::get($cacheKey);
        if ($payload === null) {
            try {
                $res = Http::timeout(15)->connectTimeout(5)->get('https://serpapi.com/search.json', [
                    'engine' => 'amazon_product', 'asin' => $asin, 'amazon_domain' => 'amazon.com',
                    'language' => 'en_US', 'api_key' => $key,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_unreachable'], 200);
            }
            if (! $res->ok()) {
                return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_error'], 200);
            }
            $payload = $this->normalizeAmazonProduct($res->json(), $asin);
            // A product page is stable for longer than a search: 30 min, and 2 min when it came back thin so a
            // transient miss re-probes soon.
            Cache::put($cacheKey, $payload, now()->addSeconds(($payload['variants'] ?? []) ? 1800 : 120));
        }
        return response()->json($payload, 200);
    }

    /** SerpAPI amazon_product -> the same shape /catalog/product-variants returns, so the modal needs no special case. */
    private function normalizeAmazonProduct(array $json, string $asin): array
    {
        $pr = $json['product_results'] ?? [];
        $title = $pr['title'] ?? null;
        $price = $pr['price'] ?? ($pr['buybox_price'] ?? null);
        if (is_array($price)) { $price = $price['value'] ?? $price['extracted_value'] ?? null; }
        $price = is_numeric($price) ? (float) $price : (is_string($price) ? (float) preg_replace('/[^0-9.]/', '', $price) : null);

        $images = [];
        foreach (($pr['images'] ?? $pr['image_gallery'] ?? $pr['media'] ?? $json['product_images'] ?? []) as $im) {
            $u = is_string($im) ? $im : ($im['link'] ?? $im['image'] ?? null);
            if ($u && ! in_array($u, $images, true)) { $images[] = $u; }
            if (count($images) >= 12) { break; }
        }
        if (! $images && ! empty($pr['thumbnail'])) { $images[] = $pr['thumbnail']; }

        // Availability: Amazon states it in words. Anything we cannot read stays UNKNOWN (null), never "sold out" —
        // the rule the whole variant layer follows.
        $availability = null;
        $stock = strtolower((string) ($pr['stock'] ?? $pr['availability'] ?? data_get($json, 'purchase_options.0.stock') ?? $pr['in_stock'] ?? ''));
        if ($stock !== '') {
            if (str_contains($stock, 'unavailable') || str_contains($stock, 'out of stock')) { $availability = false; }
            elseif (str_contains($stock, 'in stock') || $stock === '1' || $stock === 'true') { $availability = true; }
        }

        // Variant dimensions ("Size", "Color", "Flavor Name"...). Amazon returns each as its own list of options,
        // each option being a separate ASIN — so these are independent axes, exactly like a page read.
        $axes = [];
        $variants = [];
        // SerpAPI's shape: variants is an ARRAY of dimensions, each { title: "Size"|"Color"|"Flavor Name",
        // items: [{ asin, name, selected }] }. The dimension name lives in `title`, the option label in `name`.
        $selected = [];
        foreach (($pr['variants'] ?? $pr['variations'] ?? []) as $dim) {
            $name = trim((string) ($dim['title'] ?? $dim['dimension'] ?? ''));
            $items = $dim['items'] ?? $dim['values'] ?? [];
            if ($name === '' || ! is_array($items)) { continue; }
            $label = ucfirst($name);
            $values = [];
            foreach ($items as $opt) {
                $v = is_string($opt) ? $opt : trim((string) ($opt['name'] ?? $opt['title'] ?? $opt['value'] ?? ''));
                if ($v === '') { continue; }
                $values[] = $v;
                if (! empty($opt['selected'])) { $selected[$label] = $v; }
                $variants[] = [
                    'key' => $label . ':' . $v,
                    'options' => [$label => $v],
                    // Each option is its own ASIN; the parent page does not state per-option stock. Unknown, never false.
                    'available' => null,
                    'price' => isset($opt['price']) && is_numeric($opt['price']) ? (float) $opt['price'] : null,
                    'url' => ! empty($opt['asin']) ? 'https://www.amazon.com/dp/' . $opt['asin'] : null,
                ];
            }
            if ($values) {
                $lower = strtolower($name);
                $kind = str_contains($lower, 'siz') ? 'size' : (str_contains($lower, 'col') ? 'color' : 'other');
                $axes[] = ['name' => $label, 'kind' => $kind, 'values' => array_values(array_unique($values))];
            }
        }
        // No dimensions is a legitimate answer (a pack of cards, a single-SKU toy) — the page still gave us its
        // images, its price and whether it is available, which is the point of always visiting it.
        if (! $variants) {
            $variants[] = ['key' => 'single', 'options' => (object) [], 'available' => $availability, 'price' => $price];
        }

        return [
            'product' => [
                'title' => $title,
                'url' => 'https://www.amazon.com/dp/' . $asin,
                'price' => $price,
                'list_price' => null,
                'image' => $images[0] ?? null,
                'images' => $images,
            ],
            'axes' => $axes,
            'variants' => $variants,
            'axes_independent' => true,
            'selected' => $selected ?: null,
            'availability' => $availability,
            'source' => 'amazon-product',
            'checked_at' => now()->toIso8601String(),
        ];
    }

    // Amazon search: same contract as googleShop() (query → normalized products, cached,
    // fail-soft) but against SerpAPI's `amazon` engine, so the assistant can offer Amazon
    // the way it offers Google Shopping. Per-QUERY only — there is no "browse deals" mode.
    public function amazon(Request $request)
    {
        $query = trim((string) $request->input('query'));
        if ($query === '') {
            return response()->json(['products' => [], 'error' => 'need query'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['products' => [], 'error' => 'serpapi_not_configured'], 200);
        }
        $limit = $request->filled('limit') ? max(1, min(40, (int) $request->input('limit'))) : 16;
        $cacheKey = 'amazon:' . md5(mb_strtolower($query));

        $products = Cache::get($cacheKey);
        if ($products === null) {
            try {
                $res = Http::timeout(15)->connectTimeout(5)->get('https://serpapi.com/search.json', [
                    'engine' => 'amazon', 'k' => $query, 'amazon_domain' => 'amazon.com',
                    'language' => 'en_US', 'api_key' => $key,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['products' => [], 'error' => 'serpapi_unreachable'], 200);
            }
            if (! $res->ok()) {
                return response()->json(['products' => [], 'error' => 'serpapi_error'], 200);
            }
            $products = $this->normalizeAmazon($res->json());
            Cache::put($cacheKey, $products, now()->addSeconds($products ? 600 : 60));
        }

        $products = array_slice(array_values(array_filter($products, fn ($p) => ! empty($p['image']))), 0, $limit);
        if (empty($products)) {
            return response()->json(['products' => [], 'no_results' => true, 'source' => 'amazon'], 200);
        }
        return response()->json(['products' => $products, 'count' => count($products), 'source' => 'amazon']);
    }

    /** SerpAPI amazon response (organic_results) → the same product shape googleShop returns. */
    private function normalizeAmazon($json): array
    {
        $results = is_array($json) ? ($json['organic_results'] ?? null) : null;
        if (! is_array($results)) {
            return [];
        }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            $asin = $r['asin'] ?? null;
            if (! $title || ! $asin) {
                continue;
            }
            if (self::isSecondHand($r, 'Amazon', $title)) { // "Renewed" / "Refurbished" listings
                continue;
            }
            $price = $r['extracted_price'] ?? null;
            $old = $r['extracted_old_price'] ?? null;
            $onSale = $old && $price && $old > $price;
            $out[] = [
                'title'        => $title,
                'price'        => $price ?: null,
                'was'          => $onSale ? $old : null,
                'on_sale'      => $onSale,
                'discount_pct' => $onSale ? (int) round(100 * ($old - $price) / $old) : null,
                'store'        => 'Amazon',
                'merchant'     => 'Amazon',
                // Amazon titles often omit the brand ("FreeSip Stainless Steel Water Bottle" is Owala);
                // SerpAPI carries it separately — the app uses it to confirm a brand search really hit the brand.
                'brand'        => $r['brand'] ?? null,
                // Search thumbnails are 218px tall (._AC_UY218_) — ask for the 500px render
                // instead so gallery cards and the PR email aren't blurry. Same CDN, same key.
                'image'        => isset($r['thumbnail']) ? preg_replace('/\._AC_[A-Z0-9_,]+_\.(jpe?g|png)$/i', '._AC_SL500_.$1', $r['thumbnail']) : null,
                // The canonical product page — stable, no search-session junk in the query string.
                'url'          => $r['link_clean'] ?? ('https://www.amazon.com/dp/' . $asin),
                'rating'       => $r['rating'] ?? null,
                'reviews'      => $r['reviews'] ?? null,
                'source'       => 'amazon',
            ];
        }

        return $out;
    }
}
