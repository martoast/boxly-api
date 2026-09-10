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
            try {
                $params = [
                    'engine' => 'google_shopping', 'q' => $query, 'gl' => 'us', 'hl' => 'en',
                    'num' => 40, 'api_key' => $key,
                ];
                if ($location !== '') {
                    $params['location'] = $location;
                }
                $res = Http::timeout(15)->connectTimeout(5)->get('https://serpapi.com/search.json', $params);
            } catch (\Throwable $e) {
                return response()->json(['products' => [], 'error' => 'serpapi_unreachable'], 200);
            }
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
