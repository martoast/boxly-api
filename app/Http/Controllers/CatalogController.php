<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
            $failKey = 'gshop:fails';
            $downUntil = Cache::get($downKey);
            if ($downUntil !== null && (int) $downUntil > time()) {
                return response()->json(['products' => [], 'error' => 'serpapi_cooling', 'cooling' => true, 'retry_after_s' => max(1, (int) $downUntil - time())], 200);
            }
            // LEAN FIRST (2026-09-11). The heavy shape — city-level `location`, which SerpAPI resolves server
            // side, plus num=40 — is the one that times out: every real attempt today failed on it while the
            // plain national query answered in 1.7–4.5 s. `location` only sharpened prices toward the San Diego
            // warehouse, and merchant prices come from /catalog/google-product anyway, so it is no longer worth
            // trading every Google result for. It becomes the SECOND attempt: the lean answer wins the race, and
            // the richer one is tried only when we still have budget and nothing came back.
            $lean = ['engine' => 'google_shopping', 'q' => $query, 'gl' => 'us', 'hl' => 'en', 'num' => 40, 'api_key' => $key];
            $params = $lean;
            if ($location !== '') {
                $params['location'] = $location;
            }
            // SINGLE-FLIGHT (2026-09-11). Measured: three concurrent Google calls took 38 s each and dragged a
            // plain Amazon call from 4.3 s to 39.5 s — the cap never even started, because the requests were
            // QUEUING for a PHP-FPM worker. One slow engine must never cost us the fast one, so only ONE Google
            // call may hold a worker at a time; everyone else is told instantly that Google is busy and their
            // gallery is served by Amazon and the catalog. A cached query never reaches here at all.
            $flight = 'gshop:inflight';
            if (! Cache::add($flight, 1, 20)) {
                return response()->json(['products' => [], 'error' => 'serpapi_busy', 'busy' => true, 'retry_after_s' => 10], 200);
            }
            $res = null;
            // TOTAL BUDGET 11 s, not 12+12. The app gives this endpoint 12 s, so a 24 s worst case was time the
            // caller never waited for — it only burned a worker after the app had already given up. 11 s is
            // chosen against the measured reality (serp-diag): a query SerpAPI has cached answers in 0.1–4 s and
            // a cold one takes 6–20 s, so this catches the fast half in-turn and warmGoogleAfterResponse()
            // finishes the slow half for the next search instead of discarding it.
            $startedAt = microtime(true);
            try {
                $res = Http::timeout(8)->connectTimeout(3)->get('https://serpapi.com/search.json', $lean);
            } catch (\Throwable $e) {
                $res = null; // a TIMEOUT throws — do not give up here, the second attempt below is the whole point
            }
            // SECOND CHANCE, LEANER. Every failing call hit the cap exactly, which is a request that hangs rather
            // than one that is slow. The two heaviest parameters are the city-level `location` (SerpAPI resolves
            // it server-side) and `num=40`. So when the full request times out, errors, or comes back empty, try
            // once as a plain national query before declaring Google down. My first version of this retry sat
            // INSIDE the try block after the call, so a timeout threw straight past it and it never ran.
            if ($res === null || ! $res->ok() || empty(data_get($res->json(), 'shopping_results'))) {
                try {
                    // Only if the budget has room left — the retry must not double the worker's time.
                    $left = 11.0 - (microtime(true) - $startedAt);
                    if ($left >= 2.5) {
                        // HOW the first attempt failed decides the second's shape. Hard failure (timeout, no
                        // response, an HTTP error) = the engine was unreachable, so try the SAME reliable lean
                        // shape again — transient timeouts are most of what we see. Answered-but-empty = the
                        // engine works and simply had nothing for this shape, so the richer localized query is
                        // the one worth spending the rest of the budget on.
                        $answered = $res !== null && $res->ok();
                        $second = $answered ? $params : $lean;
                        $retry = Http::timeout((int) floor($left))->connectTimeout(3)->get('https://serpapi.com/search.json', $second);
                        if ($retry->ok() && ! empty(data_get($retry->json(), 'shopping_results'))) {
                            $res = $retry;
                        }
                    }
                } catch (\Throwable $e) { /* keep whatever the first attempt gave us */ }
            }
            if ($res === null) {
                Cache::forget($flight);
                // TWO STRIKES, NOT ONE (2026-09-11). SerpAPI's Google engine is INTERMITTENT, not down — the same
                // minute serves 16 rows in 4 s and then times out. Tripping on a single failure turned "works half
                // the time" into "off for 90 s at a time", so Alex saw Amazon-only galleries all day. The worker
                // pool no longer depends on this: single-flight caps Google at one worker and 9 s. So we only
                // cool after TWO failures in a row, for 60 s, and any success resets the count.
                $fails = ((int) Cache::get($failKey, 0)) + 1;
                if ($fails >= 2) {
                    Cache::forget($failKey);
                    Cache::put($downKey, time() + 60, now()->addSeconds(65));
                    $this->warmGoogleAfterResponse($query, $lean, $cacheKey);
                    return response()->json(['products' => [], 'error' => 'serpapi_unreachable', 'cooling' => true, 'retry_after_s' => 60], 200);
                }
                Cache::put($failKey, $fails, now()->addSeconds(120));
                $this->warmGoogleAfterResponse($query, $lean, $cacheKey);
                return response()->json(['products' => [], 'error' => 'serpapi_slow', 'warming' => true, 'strike' => $fails], 200);
            }
            Cache::forget($downKey);
            Cache::forget($failKey);
            if (! $res->ok()) {
                Cache::forget($flight);
                return response()->json(['products' => [], 'error' => 'serpapi_error'], 200);
            }
            $products = $this->normalizeGoogleShopping($res->json());
            // Asymmetric TTL: a non-empty result is stable for 10 min; an empty one might be
            // SerpAPI flakiness for the same query, so re-probe soon (60s) — see the shopping
            // cache note in ProductExtractController.
            Cache::put($cacheKey, $products, now()->addSeconds($products ? 600 : 60));
            Cache::forget($flight);
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
                // Google's own catalog id. A shopping ROW's link points at Google, not the store — this id is the
                // only way to reach the merchant's real product page (engine=google_product → sellers).
                'product_id'   => $r['product_id'] ?? null,
                // The handle for google_immersive_product, whose `stores` array carries the DIRECT merchant
                // product links. Without it a Google row can never reach the store's own page.
                'page_token'   => $r['immersive_product_page_token'] ?? null,
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

    // GOOGLE ROW -> THE MERCHANT'S OWN PRODUCT PAGE. A google_shopping result links to google.com, never to the
    // store (confirmed in SerpAPI's own field list: product_link is "Link to the Google item page"). So a shopper
    // tapping a Google result could never reach a product page to read variants from — the one hole in the
    // universal pipeline. google_immersive_product takes the row's page token and returns a `stores` array whose
    // entries DO carry direct merchant links; we hand back the best offer so the normal reader can take over.
    public function googleProduct(Request $request)
    {
        $token = trim((string) $request->input('page_token'));
        if ($token === '') {
            return response()->json(['offers' => [], 'error' => 'need page_token'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['offers' => [], 'error' => 'serpapi_not_configured'], 200);
        }

        $cacheKey = 'gprod:' . md5($token);
        $payload = Cache::get($cacheKey);
        if ($payload === null) {
            try {
                $res = Http::timeout(15)->connectTimeout(5)->get('https://serpapi.com/search.json', [
                    'engine' => 'google_immersive_product', 'page_token' => $token,
                    'gl' => 'us', 'hl' => 'en', 'api_key' => $key,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['offers' => [], 'error' => 'serpapi_unreachable'], 200);
            }
            if (! $res->ok()) {
                return response()->json(['offers' => [], 'error' => 'serpapi_error'], 200);
            }
            $json = $res->json();
            $pr = $json['product_results'] ?? [];

            $offers = [];
            foreach (($pr['stores'] ?? $json['stores'] ?? []) as $st) {
                $link = $st['link'] ?? $st['product_link'] ?? null;
                // Only a real merchant link is useful here — a google.com link is what we are trying to escape.
                if (! $link || str_contains(parse_url($link, PHP_URL_HOST) ?? '', 'google.')) { continue; }
                $price = $st['extracted_price'] ?? null;
                if (! is_numeric($price) && ! empty($st['price'])) { $price = (float) preg_replace('/[^0-9.]/', '', (string) $st['price']); }
                $offers[] = [
                    'merchant' => $st['name'] ?? null,
                    'url'      => $link,
                    'price'    => is_numeric($price) ? (float) $price : null,
                    'shipping' => $st['shipping'] ?? null,
                ];
                if (count($offers) >= 8) { break; }
            }

            $images = [];
            foreach (($pr['thumbnails'] ?? []) as $t) {
                $u = is_string($t) ? $t : ($t['link'] ?? null);
                if ($u && ! in_array($u, $images, true)) { $images[] = $u; }
                if (count($images) >= 12) { break; }
            }

            $payload = ['offers' => $offers, 'images' => $images, 'title' => $pr['title'] ?? null];
            Cache::put($cacheKey, $payload, now()->addSeconds($offers ? 1800 : 120));
        }
        return response()->json($payload, 200);
    }

    // Amazon search: same contract as googleShop() (query → normalized products, cached,
    // fail-soft) but against SerpAPI's `amazon` engine, so the assistant can offer Amazon
    // the way it offers Google Shopping. Per-QUERY only — there is no "browse deals" mode.
    /** DIAGNOSTIC (2026-09-11): is Google Shopping failing on SerpAPI's side or ours? Our code catches the
     * timeout and throws the detail away, so this runs the probes with a LONG cap and reports exactly what came
     * back — HTTP status, elapsed seconds, SerpAPI's own error string, result count — for google_shopping beside
     * amazon on the same host, same key, same TLS endpoint. The key itself is never echoed. Cheap: three probes.
     * Also answers "would a bigger budget help?" — if the engine answers at 14 s, our 6 s cap is the problem; if
     * it hangs to 30 s or returns an error, it is theirs. */
    /** FINISH THE SLOW CALL AFTER THE SHOPPER HAS ALREADY BEEN ANSWERED (2026-09-11).
     * Measured with /catalog/serp-diag: SerpAPI answers a query it has cached in 0.1–4 s, but one it must fetch
     * live from Google takes 6–20 s — the engine is not down, it is SLOW ON COLD QUERIES since their 9/10
     * incident. Our 9 s budget was turning those slow successes into failures and then cooling the breaker, so
     * Google looked permanently dead. We cannot make the shopper wait 20 s, but we must not throw the work away
     * either: the request keeps running after the response is sent, with a 25 s cap, and its result lands in the
     * same cache key. The next search for those words — a refinement, a repeat, another shopper — is instant.
     * The warm lock keeps one slow query from stacking up behind itself.
     * IT MUST BE A REAL QUEUED JOB, not ->afterResponse(): this API is served by `artisan serve` (see
     * supervisord.conf), which has no fastcgi_finish_request, so "after the response" still blocked the caller —
     * measured 35.6 s on a cold query, holding the very worker the single-flight change had just freed. The
     * supervisor already runs `queue:work --queue=high,default`, so the slow call belongs there. */
    private function warmGoogleAfterResponse(string $query, array $lean, string $cacheKey): void
    {
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return;
        }
        $warmKey = 'gshop:warm:' . md5($query);
        if (! Cache::add($warmKey, 1, 40)) {
            return;
        }
        dispatch(function () use ($lean, $key, $cacheKey, $warmKey) {
            try {
                $res = Http::timeout(25)->connectTimeout(5)->get('https://serpapi.com/search.json', $lean + ['api_key' => $key]);
                if ($res->ok() && ! empty(data_get($res->json(), 'shopping_results'))) {
                    $products = $this->normalizeGoogleShopping($res->json());
                    if ($products) {
                        Cache::put($cacheKey, $products, now()->addSeconds(900));
                        Cache::forget('gshop:down');
                        Cache::forget('gshop:fails');
                    }
                }
            } catch (\Throwable $e) {
                // a warm that fails is simply a cache that stays cold — never surfaced to anyone
            } finally {
                Cache::forget($warmKey);
            }
        })->onQueue('default');
    }

    /** EVERY HEALTHY ENGINE AT ONCE (Alex, 2026-09-11: "we can have ebay and bing help supplement to get more
     * results, and in the case where say google api is down the others can help supplement... can we have these
     * calls be in parallel and not really take time at all, and just ignore the endpoints that take too long to
     * return and use the ones that are healthy").
     *
     * One request, one PHP worker, N SerpAPI engines IN FLIGHT TOGETHER via Http::pool (Guzzle async), so the
     * wall clock is the SLOWEST engine, not their sum. Whoever answers inside the budget contributes rows;
     * whoever does not is simply absent and is marked unhealthy for a minute so it stops costing us latency.
     * This is also the answer to Google's cold-query slowness: the gallery no longer depends on any one engine. */
    public function webSearch(Request $request)
    {
        $query = trim((string) $request->input('query'));
        if ($query === '') {
            return response()->json(['products' => [], 'error' => 'need query'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['products' => [], 'error' => 'serpapi_not_configured'], 200);
        }
        $limit = $request->filled('limit') ? max(1, min(60, (int) $request->input('limit'))) : 48;
        // 9 s: the pool returns when the slowest engine inside the budget does. The app allows 12 s.
        $budget = max(3, min(15, (int) $request->input('budget_s', 9)));
        $want = array_values(array_filter(array_map('strval', (array) $request->input('engines', []))));
        $engines = $want ?: self::defaultEngines($query);

        $out = [];
        $sources = [];
        $pending = [];
        foreach ($engines as $name) {
            $spec = self::ENGINE_SPECS[$name] ?? null;
            if (! $spec) { continue; }
            $cacheKey = 'web:' . $name . ':' . md5(mb_strtolower($query));
            $hit = Cache::get($cacheKey);
            if ($hit !== null) {
                $out[$name] = $hit;
                $sources[$name] = ['rows' => count($hit), 'status' => 'cached'];
                continue;
            }
            if (Cache::get('web:sick:' . $name) !== null) {
                $sources[$name] = ['rows' => 0, 'status' => 'cooling'];
                continue;
            }
            $pending[$name] = ['params' => $spec['params']($query) + ['engine' => $spec['engine'], 'api_key' => $key], 'cache' => $cacheKey];
        }

        if ($pending) {
            $responses = Http::pool(function (Pool $pool) use ($pending, $budget) {
                $calls = [];
                foreach ($pending as $name => $p) {
                    $calls[] = $pool->as($name)->timeout($budget)->connectTimeout(4)->get('https://serpapi.com/search.json', $p['params']);
                }
                return $calls;
            });
            foreach ($pending as $name => $p) {
                $res = $responses[$name] ?? null;
                if (! $res instanceof Response) {           // a throwable lands here instead of a response
                    Cache::put('web:sick:' . $name, 1, now()->addSeconds(60));
                    $sources[$name] = ['rows' => 0, 'status' => 'timeout'];
                    continue;
                }
                if (! $res->ok()) {
                    $sources[$name] = ['rows' => 0, 'status' => 'http_' . $res->status()];
                    continue;
                }
                $rows = self::ENGINE_SPECS[$name]['normalize']($this, $res->json());
                // An engine that answered but found nothing is HEALTHY — cache the empty briefly so we do not
                // pay for the same miss twice, and never mark it sick.
                Cache::put($p['cache'], $rows, now()->addSeconds($rows ? 600 : 90));
                $out[$name] = $rows;
                $sources[$name] = ['rows' => count($rows), 'status' => 'ok'];
            }
        }

        // INTERLEAVE, never concatenate: one engine with 40 rows must not bury the other five. Deals lead
        // (that is the product's promise), then round-robin so every engine is represented near the top.
        $all = [];
        foreach ($out as $name => $rows) { foreach ($rows as $r) { $all[] = $r + ['engine' => $name]; } }
        $seen = [];
        $keep = function (array $r) use (&$seen): bool {
            $k = mb_substr(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) ($r['title'] ?? ''))), 0, 60);
            if ($k === '' || isset($seen[$k])) { return false; }
            $seen[$k] = true;
            return ! empty($r['image']);   // a blank tile is never shown
        };
        // The DEALS are interleaved by engine too. Sorting them by discount alone put five Amazon rows at the
        // top of a five-engine gallery, which is the burying this whole change exists to prevent: each engine's
        // deals are ranked by depth, then we take one from each in turn.
        $dealQ = [];
        foreach ($out as $name => $rows) {
            $mine = [];
            foreach ($rows as $r) { if (! empty($r['on_sale']) && ! empty($r['discount_pct']) && $keep($r)) { $mine[] = $r + ['engine' => $name]; } }
            usort($mine, fn ($a, $b) => ($b['discount_pct'] ?? 0) <=> ($a['discount_pct'] ?? 0));
            if ($mine) { $dealQ[$name] = $mine; }
        }
        $restQ = [];
        foreach ($out as $name => $rows) {
            $mine = [];
            foreach ($rows as $r) { if ($keep($r)) { $mine[] = $r + ['engine' => $name]; } }
            if ($mine) { $restQ[$name] = $mine; }
        }
        $roundRobin = function (array $queues): array {
            $merged = [];
            for ($i = 0; ; $i++) {
                $any = false;
                foreach ($queues as $rows) { if (isset($rows[$i])) { $merged[] = $rows[$i]; $any = true; } }
                if (! $any) { break; }
            }
            return $merged;
        };
        $products = array_slice(array_merge($roundRobin($dealQ), $roundRobin($restQ)), 0, $limit);

        return response()->json([
            'products' => $products,
            'count' => count($products),
            'sources' => $sources,
            'engines' => array_keys($sources),
            'source' => 'web',
        ], 200);
    }

    /** Which engines to ask for THIS query. The broad five always; the specialists only when the words call for
     * them, because every engine is a paid search. */
    private static function defaultEngines(string $query): array
    {
        $base = ['google_shopping', 'amazon', 'ebay', 'bing_shopping', 'walmart'];
        if (preg_match('/\b(tool|tools|drill|saw|hammer|wrench|screwdriver|ladder|paint|lumber|plywood|faucet|toilet|sink|tile|grout|caulk|hose|mower|trimmer|generator|insulation|drywall|plumbing|electrical|garage|shed|fence|deck|herramienta|taladro|sierra|martillo|llave|pintura|manguera|podadora|plomer|jardin|jardín)\b/iu', $query)) {
            $base[] = 'home_depot';
        }
        return $base;
    }

    /** One row per SerpAPI engine: how to ask it, and how to turn its answer into a gallery row. Adding an engine
     * is adding an entry here — nothing else in the fan-out knows their names. */
    private const ENGINE_SPECS = [
        'google_shopping' => [
            'engine' => 'google_shopping',
            'params' => [self::class, 'paramsGoogleShopping'],
            'normalize' => [self::class, 'rowsGoogleShopping'],
        ],
        'amazon' => [
            'engine' => 'amazon',
            'params' => [self::class, 'paramsAmazon'],
            'normalize' => [self::class, 'rowsAmazon'],
        ],
        'ebay' => [
            'engine' => 'ebay',
            'params' => [self::class, 'paramsEbay'],
            'normalize' => [self::class, 'rowsEbay'],
        ],
        'bing_shopping' => [
            'engine' => 'bing_shopping',
            'params' => [self::class, 'paramsBing'],
            'normalize' => [self::class, 'rowsBing'],
        ],
        'walmart' => [
            'engine' => 'walmart',
            'params' => [self::class, 'paramsWalmart'],
            'normalize' => [self::class, 'rowsWalmart'],
        ],
        'home_depot' => [
            'engine' => 'home_depot',
            'params' => [self::class, 'paramsHomeDepot'],
            'normalize' => [self::class, 'rowsHomeDepot'],
        ],
    ];

    public static function paramsGoogleShopping(string $q): array { return ['q' => $q, 'gl' => 'us', 'hl' => 'en', 'num' => 40]; }
    public static function paramsAmazon(string $q): array { return ['k' => $q, 'amazon_domain' => 'amazon.com', 'language' => 'en_US']; }
    public static function paramsEbay(string $q): array { return ['_nkw' => $q, 'ebay_domain' => 'ebay.com', '_ipg' => 50, 'LH_ItemCondition' => 1000, 'LH_BIN' => 1]; }
    public static function paramsBing(string $q): array { return ['q' => $q]; }
    public static function paramsWalmart(string $q): array { return ['query' => $q]; }
    public static function paramsHomeDepot(string $q): array { return ['q' => $q]; }

    public static function rowsGoogleShopping($self, $json): array { return $self->normalizeGoogleShopping($json); }
    public static function rowsAmazon($self, $json): array { return $self->normalizeAmazon($json); }

    /** eBay. `LH_ItemCondition=1000` + `LH_BIN=1` already ask for NEW, Buy-It-Now only — auctions and used goods
     * are not something Boxly can promise a delivery date on — and isSecondHand() catches whatever slips past.
     * Its price is a from/to range object, so a range takes its low end. */
    public static function rowsEbay($self, $json): array
    {
        $results = is_array($json) ? ($json['organic_results'] ?? null) : null;
        if (! is_array($results)) { return []; }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            $link = $r['link'] ?? null;
            if (! $title || ! $link) { continue; }
            $cond = (string) ($r['condition'] ?? '');
            if ($cond !== '' && ! preg_match('/\bnew\b/i', $cond)) { continue; }
            if (self::isSecondHand($r + ['tag' => $cond], 'eBay', $title)) { continue; }
            $price = data_get($r, 'price.extracted') ?? data_get($r, 'price.from.extracted') ?? data_get($r, 'price.extracted_value');
            if (! is_numeric($price)) { continue; }
            $out[] = [
                'title' => $title, 'price' => (float) $price, 'was' => null, 'on_sale' => false, 'discount_pct' => null,
                'store' => 'eBay', 'merchant' => data_get($r, 'seller.username') ?: 'eBay', 'brand' => null,
                'image' => $r['thumbnail'] ?? null, 'url' => $link,
                'rating' => null, 'reviews' => null, 'source' => 'ebay',
            ];
        }
        return $out;
    }

    /** Bing Shopping. `external_link` is the merchant's own page; `link` is a Bing redirect, which our product
     * reader cannot follow to variants — so a row without an external link is not worth showing. */
    public static function rowsBing($self, $json): array
    {
        $results = is_array($json) ? ($json['shopping_results'] ?? null) : null;
        if (! is_array($results)) { return []; }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            $url = $r['external_link'] ?? $r['link'] ?? null;
            $price = $r['extracted_price'] ?? null;
            if (! $title || ! $url || ! is_numeric($price)) { continue; }
            $seller = (string) ($r['seller'] ?? $r['source'] ?? '');
            if (self::isSecondHand($r, $seller, $title)) { continue; }
            $img = $r['thumbnail'] ?? (is_array($r['thumbnails'] ?? null) ? ($r['thumbnails'][0] ?? null) : null);
            $out[] = [
                'title' => $title, 'price' => (float) $price, 'was' => null, 'on_sale' => false, 'discount_pct' => null,
                'store' => $seller ?: 'Bing Shopping', 'merchant' => $seller ?: null, 'brand' => null,
                'image' => $img, 'url' => $url, 'rating' => $r['rating'] ?? null, 'reviews' => $r['reviews'] ?? null,
                'source' => 'bing',
            ];
        }
        return $out;
    }

    /** Walmart. Carries real stock truth (`out_of_stock`) and a real was-price, so its rows can be honest about
     * both — we drop what it says is out of stock rather than show a tile nobody can buy. */
    public static function rowsWalmart($self, $json): array
    {
        $results = is_array($json) ? ($json['organic_results'] ?? null) : null;
        if (! is_array($results)) { return []; }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            $url = $r['product_page_url'] ?? $r['link'] ?? null;
            $price = data_get($r, 'primary_offer.offer_price');
            if (! $title || ! $url || ! is_numeric($price)) { continue; }
            if (! empty($r['out_of_stock'])) { continue; }
            if (self::isSecondHand($r, 'Walmart', $title)) { continue; }
            $was = data_get($r, 'primary_offer.min_price') ?: data_get($r, 'primary_offer.list_price');
            $onSale = is_numeric($was) && $was > $price;
            $out[] = [
                'title' => $title, 'price' => (float) $price, 'was' => $onSale ? (float) $was : null,
                'on_sale' => $onSale, 'discount_pct' => $onSale ? (int) round(100 * ($was - $price) / $was) : null,
                'store' => 'Walmart', 'merchant' => $r['seller_name'] ?? 'Walmart', 'brand' => null,
                'image' => $r['thumbnail'] ?? null, 'url' => $url,
                'rating' => $r['rating'] ?? null, 'reviews' => $r['reviews'] ?? null, 'source' => 'walmart',
            ];
        }
        return $out;
    }

    /** Home Depot, asked only for tool and home-improvement words (see defaultEngines). */
    public static function rowsHomeDepot($self, $json): array
    {
        $results = is_array($json) ? ($json['products'] ?? null) : null;
        if (! is_array($results)) { return []; }
        $out = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? null;
            $url = $r['link'] ?? null;
            $price = $r['price'] ?? null;
            if (! $title || ! $url || ! is_numeric($price)) { continue; }
            $was = $r['price_was'] ?? null;
            $onSale = is_numeric($was) && $was > $price;
            $img = is_array($r['thumbnails'] ?? null) ? (is_array($r['thumbnails'][0] ?? null) ? ($r['thumbnails'][0][0] ?? null) : ($r['thumbnails'][0] ?? null)) : ($r['thumbnail'] ?? null);
            $out[] = [
                'title' => $title, 'price' => (float) $price, 'was' => $onSale ? (float) $was : null,
                'on_sale' => $onSale, 'discount_pct' => $onSale ? (int) round(100 * ($was - $price) / $was) : null,
                'store' => 'The Home Depot', 'merchant' => 'The Home Depot', 'brand' => $r['brand'] ?? null,
                'image' => $img, 'url' => $url, 'rating' => $r['rating'] ?? null, 'reviews' => $r['reviews'] ?? null,
                'source' => 'home_depot',
            ];
        }
        return $out;
    }

    public function serpDiag(Request $request)
    {
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['error' => 'serpapi_not_configured'], 200);
        }
        $q = trim((string) $request->input('q', 'soccer ball')) ?: 'soccer ball';
        $location = (string) config('services.serpapi.location');
        // Every probe is a paid SerpAPI search, so the default run is the two that answer the question — is
        // Google slow while Amazon is fine — and the slow localized shape costs a credit only on ?full=1.
        $probes = [
            'google_shopping_lean' => ['engine' => 'google_shopping', 'q' => $q, 'gl' => 'us', 'hl' => 'en', 'num' => 40],
            'amazon_baseline' => ['engine' => 'amazon', 'k' => $q, 'amazon_domain' => 'amazon.com', 'language' => 'en_US'],
        ];
        if ($request->boolean('full')) {
            $probes['google_shopping_located'] = array_filter(['engine' => 'google_shopping', 'q' => $q, 'gl' => 'us', 'hl' => 'en', 'num' => 40, 'location' => $location ?: null]);
        }
        $out = [];
        foreach ($probes as $name => $params) {
            $t0 = microtime(true);
            $row = ['probe' => $name];
            try {
                $res = Http::timeout(30)->connectTimeout(5)->get('https://serpapi.com/search.json', $params + ['api_key' => $key]);
                $json = $res->json();
                $row['http'] = $res->status();
                $row['serpapi_error'] = is_array($json) ? (string) ($json['error'] ?? '') : '';
                $row['search_status'] = is_array($json) ? (string) data_get($json, 'search_metadata.status', '') : '';
                $row['results'] = is_array($json) ? count((array) ($json['shopping_results'] ?? $json['organic_results'] ?? [])) : 0;
            } catch (\Throwable $e) {
                $row['http'] = null;
                $row['exception'] = class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 140);
            }
            $row['seconds'] = round(microtime(true) - $t0, 2);
            $out[] = $row;
        }
        return response()->json(['query' => $q, 'probes' => $out, 'note' => 'same host, same key, same endpoint — compare google_shopping against amazon'], 200);
    }

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
