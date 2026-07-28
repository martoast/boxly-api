<?php

namespace App\Http\Controllers;

use App\Jobs\PrimeShoppingCache;
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
     * FULL product detail for a pasted URL — the assisted-purchase form's
     * pre-fill. Routes the URL to the best extractor (Amazon/Walmart structured via
     * ScraperAPI, Shopify variant JSON, WooCommerce/Magento/JSON-LD HTML cascade)
     * and returns { title, price, was, on_sale, description, options[], variants[],
     * images[] } WITH selectable variants. Falls back to the lightweight parse so a
     * store we can't fully scrape still pre-fills title/price/image. Cached briefly.
     */
    public function scrape(Request $request)
    {
        $validated = $request->validate(['url' => 'required|url|max:2000']);
        $url = $validated['url'];
        $store = $this->storeFromUrl($url);

        $cacheKey = 'scrape:' . md5($url . '|z:' . config('services.scraperapi.zip'));
        if (($cached = Cache::get($cacheKey)) !== null) {
            return response()->json(['success' => true, 'data' => $cached, 'cached' => true]);
        }

        // 1) Full detail with variants.
        $detail = $this->extractDetail($url);

        $isEmpty = fn ($d) => ! $d || (empty($d['title']) && ($d['price'] ?? null) === null && empty($d['images']));

        // 2) Lightweight fallback (title/price/image only) when the full pass is thin.
        //    Try a plain fetch first (fast); if it's still missing the title or price,
        //    escalate to a RENDERED fetch, which clears most simple bot walls and lets
        //    JS-rendered stores expose their JSON-LD / price.
        if ($isEmpty($detail)) {
            $detail = $this->lightScrape($url, false);
            if ($isEmpty($detail) || ($detail['price'] ?? null) === null || empty($detail['title'])) {
                $rendered = $this->lightScrape($url, true);
                if ($rendered && ! $isEmpty($rendered)) {
                    // Prefer whichever pass has more (rendered usually wins on hard stores).
                    if ($isEmpty($detail)) {
                        $detail = $rendered;
                    } else {
                        $detail['title']       = $detail['title'] ?: $rendered['title'];
                        $detail['price']       = $detail['price'] ?? $rendered['price'];
                        $detail['description'] = $detail['description'] ?: $rendered['description'];
                        $detail['images']      = $detail['images'] ?: $rendered['images'];
                    }
                }
            }
        }

        if ($isEmpty($detail)) {
            return response()->json([
                'success' => false,
                'message' => 'Could not read the product page.',
                'data'    => ['source_url' => $url, 'store' => $store],
            ], 422);
        }

        $data = array_merge([
            'source_url' => $url,
            'store'      => $store,
            'currency'   => 'USD',
        ], $detail);

        Cache::put($cacheKey, $data, now()->addMinutes(10));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Lightweight single-page extract (title/price/image/description only) used as
     * the /scrape fallback. $render toggles a JS-rendered fetch (bypasses simple bot
     * walls at the cost of latency). Returns the same detail shape or null.
     */
    private function lightScrape(string $url, bool $render): ?array
    {
        $product = $this->parseShopify($url); // Shopify .js — instant win when it works
        if (! $product) {
            $html = $this->fetch($url, $render);
            if (! $html) {
                return null;
            }
            $product = $this->parseJsonLd($html) ?? $this->parseMeta($html);
            if ($product) {
                if (empty($product['price'])) {
                    $product['price'] = $this->priceFromHtml($html);
                }
                if (empty($product['image'])) {
                    $product['image'] = $this->meta($html, 'og:image') ?? $this->meta($html, 'twitter:image');
                }
            }
        }
        if (! $product) {
            return null;
        }

        return [
            'title'       => $product['title'] ?? null,
            'price'       => isset($product['price']) ? (float) $product['price'] : null,
            'was'         => null,
            'on_sale'     => false,
            'description' => $product['description'] ?? null,
            'options'     => [],
            'variants'    => [],
            'images'      => array_values(array_filter([$product['image'] ?? null])),
        ];
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

        // SPEED: a single Google Shopping pass. We used to also fire category-aware
        // passes at priority retailers (Target/Walmart) to surface them, but that
        // tripled the SerpAPI calls (and the slow-tail risk) for a chat that needs
        // to feel instant. The base pass already returns the big chains for normal
        // products, and the AI curate step (server/utils/curate.ts) now ranks
        // chains up and resale marketplaces down — so we keep coverage without the
        // extra round-trips. (Set $retailers to re-enable priority passes.)
        $retailers = [];

        // One Google Shopping query per source, fetched in parallel (cached each).
        $queries = array_merge([$baseQ], array_map(fn ($r) => $baseQ . ' ' . $r, $retailers));
        $byQuery = $this->multiShopping($queries, $start);

        $general = $byQuery[$baseQ] ?? [];

        // A store-biased query with a generic term (e.g. "Gymshark clothing") can come
        // back empty from Google Shopping even though the brand clearly has a catalog.
        // Retry ONCE with just the brand — which reliably returns the store's products —
        // so we never dead-end a customer who searched a real store. One extra call,
        // and only when the primary pass found nothing.
        if (empty($general) && $store !== '' && $this->slugify($baseQ) !== $this->slugify($store)) {
            $fallback = $this->multiShopping([$store], $start);
            $general = $fallback[$store] ?? [];
            if (! empty($general)) {
                $baseQ = $store; // reflect the query we actually served (cache-prime, ranking)
            }
        }
        // SPEED: the ScraperAPI structured-shopping fallback is reliable but SLOW
        // (ultra_premium, ~20-40s) and was the root of the 20-25s tail whenever
        // SerpAPI returned nothing. Take it OFF the request path: serve what we
        // have now (possibly empty) and PRIME the cache in the background so the
        // NEXT identical search is instant. Dedupe (Cache::add) so we don't queue
        // the same 40s scrape repeatedly inside the window. (Runs on the queue —
        // the prod worker handles it; no-op without a ScraperAPI key, e.g. local.)
        if (empty($general) && $start === 0 && config('services.scraperapi.key')
            && Cache::add('scraperprime:' . md5($baseQ) . ':' . $start, 1, 300)) {
            PrimeShoppingCache::dispatch($baseQ, $start);
        }

        // GUARANTEE THE NAMED STORE. Google Shopping frequently returns only
        // RESELLERS (eBay, TikTok Shop, Poshmark…) for a direct-to-consumer brand
        // and omits the brand's OWN store entirely — so a customer who explicitly
        // asked for e.g. "DFYNE" sees everyone BUT DFYNE. Floating/ranking can't fix
        // that: the brand simply isn't in the result set. When a store was named and
        // NONE of the Shopping results are actually from it, pull the brand's own
        // catalog straight from its site and lead with it. (First page only — later
        // pages paginate Shopping.)
        if ($store !== '' && $start === 0) {
            $want = $this->slugify($store);
            $hasStore = false;
            foreach ($general as $p) {
                $src = $this->slugify((string) ($p['store'] ?? ''));
                if ($src !== '' && (str_contains($src, $want) || str_contains($want, $src))) {
                    $hasStore = true;
                    break;
                }
            }
            if (! $hasStore) {
                $own = $this->brandOwnCatalog($store, $limit);
                if (! empty($own)) {
                    $general = array_merge($own, $general);
                }
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

        // Base ordering only: RELEVANCE tier (a color/attribute like "owala pink"
        // keeps pink on top), then priced-before-unpriced, otherwise keep Google
        // Shopping's own order (PHP 8 sort is stable). The FINAL "best results
        // first" ranking (trust/brand/quality) is done AI-first by a fast model in
        // the assistant's search_products tool — not hard-coded here.
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
        // only. The AI search is now AUTHENTICATED-ONLY, so we log ONLY when a real
        // user is behind the call — never guests. This endpoint stays callable
        // without auth for internal image resolution (e.g. /api/card-image starter
        // thumbnails) and any leftover public surface, but those must NOT pollute
        // the AI-search analytics as "Invitado" rows.
        $userId = optional(auth('sanctum')->user())->id ?? optional($request->user())->id;
        if ($start === 0 && $userId) {
            try {
                SearchEvent::create([
                    'user_id'         => $userId,
                    'conversation_id' => $request->integer('conversation_id') ?: null,
                    'type'            => SearchEvent::TYPE_SEARCH,
                    'query'           => mb_substr(trim($validated['query']), 0, 255),
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
     * Lightweight WEB SEARCH (Google organic via SerpAPI) for the AI assistant's
     * web_search tool. The chat uses this only on the Gemini provider path —
     * Claude has its own native server-side web search, but Gemini's built-in
     * google_search can't coexist with our custom function tools, so we expose
     * web search as a plain endpoint. Returns a few organic results (title, url,
     * snippet) the model turns into product URLs for show_products/extract_product.
     * Cached 30 min, same as shopping. US-pinned (gl/hl/location) like all our
     * fetches so prices/results aren't localized to Mexico.
     */
    public function webSearch(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'num'   => 'nullable|integer|min:1|max:10',
        ]);
        $q = trim($validated['query']);
        $num = (int) ($validated['num'] ?? 6);
        $key = config('services.serpapi.key');
        $location = config('services.serpapi.location');

        if ($q === '' || ! $key) {
            return response()->json(['success' => true, 'data' => ['results' => []]]);
        }

        $cacheKey = 'serp_web:' . md5($q . '|' . $location) . ':' . $num;
        $results = Cache::get($cacheKey);
        if ($results === null) {
            try {
                $params = [
                    'engine' => 'google', 'q' => $q, 'gl' => 'us', 'hl' => 'en',
                    'num' => $num, 'api_key' => $key,
                ];
                if (! empty($location)) {
                    $params['location'] = $location;
                }
                $res = Http::timeout(12)->connectTimeout(5)->get('https://serpapi.com/search.json', $params);
                $organic = $res->successful() ? ($res->json('organic_results') ?? []) : [];
                $results = array_values(array_filter(array_map(function ($r) {
                    $url = $r['link'] ?? null;
                    if (! $url) {
                        return null;
                    }
                    return [
                        'title'   => $r['title'] ?? '',
                        'url'     => $url,
                        'snippet' => $r['snippet'] ?? '',
                    ];
                }, array_slice($organic, 0, $num))));
                Cache::put($cacheKey, $results, now()->addMinutes(30));
            } catch (\Throwable $e) {
                $results = [];
            }
        }

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /**
     * Full product detail for the modal: more images, description, and direct
     * seller links — fetched lazily (one SerpAPI call) when a product opens,
     * keyed by the immersive page token from search_products.
     */
    public function details(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:6000',
            // Optional: the seller the caller is showing. Google's immersive page
            // lists EVERY store carrying the product, and the first one is not
            // necessarily the one whose price/condition the caller displayed —
            // sending the shopper to a different merchant than the row they
            // clicked is a bait-and-switch. When given, we prefer that store.
            'store' => 'nullable|string|max:120',
        ]);

        $key = config('services.serpapi.key');
        if (! $key) {
            return response()->json(['success' => false, 'message' => 'Not configured.'], 503);
        }

        // One SerpAPI call per token; cache an hour. The shopper extension
        // resolves a merchant link on every listing click, and without this a
        // second look at the same product would pay for it twice.
        $cacheKey = 'immersive:' . md5($validated['token']) . ':' . $this->slugify($validated['store'] ?? '');
        if (($cached = Cache::get($cacheKey)) !== null) {
            return response()->json(['success' => true, 'data' => $cached, 'cached' => true]);
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

        // A direct merchant URL, much better than the Google view link for "see
        // it" and for placing the request. Prefer the store the caller asked for
        // so the shopper lands on the exact listing they clicked; otherwise take
        // the first seller with a real link.
        $want = $this->slugify($validated['store'] ?? '');
        $link = null;
        $fallback = null;
        foreach ($pr['stores'] ?? [] as $s) {
            if (empty($s['link'])) {
                continue;
            }
            $fallback = $fallback ?? $s['link'];
            $name = $this->slugify((string) ($s['name'] ?? ''));
            if ($want !== '' && $name !== '' && (str_starts_with($name, $want) || str_starts_with($want, $name))) {
                $link = $s['link'];
                break;
            }
        }
        $link = $link ?? $fallback;

        $data = [
            'images'      => array_slice($images, 0, 8),
            'description' => $pr['about_the_product']['description'] ?? null,
            'link'        => $link,
        ];

        if ($link || $images) {
            Cache::put($cacheKey, $data, now()->addHour());
        }

        return response()->json(['success' => true, 'data' => $data]);
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
            'images' => [], 'description' => null, 'available' => true,
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
                // Best-effort price + sale (Shopify enrich below overrides if available).
                if ($out['price'] === null && isset($imm['price'])) {
                    $out['price']   = $imm['price'];
                    $out['was']     = $imm['was'] ?? null;
                    $out['on_sale'] = $imm['on_sale'] ?? false;
                }
            }
        }

        $buy = $out['buy_url'];
        if ($buy) {
            $out['store'] = $this->storeFromUrl($buy);
        }

        // 2) Lightweight enrich — extra images, description and price ONLY. The
        //    modal just needs photos + a description + the real link; the customer
        //    picks size/color on the store's own page (or Boxly handles it on an
        //    assisted purchase). So: NO variant scraping, NO rendered fetches, NO
        //    Amazon/Walmart structured calls. Skip entirely when immersive already
        //    gave us enough, or the link is a Google view link. Shopify exposes a
        //    cheap JSON feed; every other store rides on the immersive data + the
        //    search-card image (which we already have for free).
        // Enrich from the store when possible. Shopify exposes a cheap JSON feed
        // with extra images, description, price AND live per-variant stock; a
        // non-Shopify URL returns null instantly (no network). We ALWAYS check
        // Shopify — even when immersive already gave photos — because Google's
        // index can be days stale and we must not send the user to a sold-out page.
        if ($buy && ! $this->isGoogleLink($buy)) {
            if ($enrich = $this->enrichLight($buy)) {
                if (! empty($enrich['images'])) {
                    // Prefer store images (more, higher-res); keep immersive as backup.
                    $out['images'] = array_merge($enrich['images'], $out['images']);
                }
                if (empty($out['description']) && ! empty($enrich['description'])) {
                    $out['description'] = $enrich['description'];
                }
                if ($out['price'] === null && isset($enrich['price'])) {
                    $out['price']   = $enrich['price'];
                    $out['was']     = $enrich['was'] ?? null;
                    $out['on_sale'] = $enrich['on_sale'] ?? false;
                }
                if (array_key_exists('available', $enrich)) {
                    $out['available'] = $enrich['available'];
                }
            }
        }

        // Final safety net: an unreleased drop (future date in the title) is gated
        // even if its variants report available — mark it unavailable so the modal
        // blocks the dead "go to store" link.
        if ($this->isFutureDrop($validated['title'] ?? $out['title'] ?? null)) {
            $out['available'] = false;
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

    /** Build a variant matrix from a list of variant Product nodes. Prefers clean
     *  schema.org attributes (color/size/additionalProperty) so the UI shows real
     *  "Color" / "Talla" selectors; falls back to the full variant name otherwise. */
    private function variantsFromNodes(array $nodes): ?array
    {
        $variants = [];
        $optionVals = [];  // optionName => [value => true] (insertion order preserved)
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
            if ($price !== null && ($minP === null || $price < $minP)) {
                $minP = $price;
            }

            // Prefer clean attributes; only fall back to the full name when none exist.
            $opts = [];
            if ($c = $this->scalar($n['color'] ?? null)) {
                $opts['Color'] = $c;
            }
            if ($s = $this->scalar($n['size'] ?? null)) {
                $opts['Talla'] = $s;
            }
            foreach (($n['additionalProperty'] ?? []) as $ap) {
                if (! is_array($ap)) {
                    continue;
                }
                $pn = $this->scalar($ap['name'] ?? null);
                $pv = $this->scalar($ap['value'] ?? null);
                if ($pn && $pv) {
                    $label = $this->optionLabel($pn);
                    if (! isset($opts[$label])) {
                        $opts[$label] = $pv;
                    }
                }
            }
            if (! $opts) {
                $label = $this->scalar($n['name'] ?? null) ?? ($n['sku'] ?? null);
                if (! $label) {
                    continue;
                }
                $opts['Opción'] = (string) $label;
            }

            foreach ($opts as $k => $v) {
                $optionVals[$k][$v] = true;
            }
            $variants[] = [
                'id' => $n['sku'] ?? null,
                'title' => implode(' / ', array_values($opts)),
                'options' => $opts,
                'price' => $price, 'was' => null, 'on_sale' => false, 'available' => $avail,
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
            'price' => $minP, 'was' => null, 'on_sale' => false,
            'options' => $options,
            'variants' => $variants,
        ];
    }

    /** Coerce a JSON-LD value (string, number, or {name|value|@value} object) to a clean string. */
    private function scalar($v): ?string
    {
        if (is_string($v)) {
            $v = trim($v);
            return $v !== '' ? $v : null;
        }
        if (is_numeric($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            foreach (['name', 'value', '@value', 0] as $k) {
                if (isset($v[$k])) {
                    return $this->scalar($v[$k]);
                }
            }
        }
        return null;
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
        $chosen = null;
        foreach ($pr['stores'] ?? [] as $s) {
            if (! empty($s['link'])) { $link = $s['link']; $chosen = $s; break; }
        }

        // Best-effort pricing + SALE detection for non-Shopify stores (Coach,
        // Nike…) that don't expose a cheap JSON feed. SerpAPI's field names vary,
        // so scan the chosen store and the product node for a current price and
        // any "original/old/comparable" price; if the old one is higher, it's a sale.
        $price = $this->priceNum($chosen['extracted_price'] ?? $chosen['price'] ?? $pr['extracted_price'] ?? $pr['price'] ?? null);
        $was = null;
        foreach (['extracted_original_price', 'original_price', 'extracted_old_price', 'old_price', 'comparable_price', 'comparable_value'] as $k) {
            $cand = $this->priceNum(($chosen[$k] ?? null) ?? ($pr[$k] ?? null));
            if ($cand) { $was = $cand; break; }
        }
        // Fallback: a prices[] list with two distinct numbers → higher is the was.
        if ($was === null && is_array($pr['prices'] ?? null)) {
            $nums = array_values(array_filter(array_map(fn ($x) => $this->priceNum($x), $pr['prices'])));
            if (count($nums) >= 2) {
                $hi = max($nums); $lo = min($nums);
                if ($hi > $lo) { $price = $price ?? $lo; $was = $hi; }
            }
        }
        $onSale = $was && $price && $was > $price;

        return [
            'images' => array_slice($images, 0, 8),
            'description' => $pr['about_the_product']['description'] ?? null,
            'link' => $link,
            'price' => $price,
            'was' => $onSale ? $was : null,
            'on_sale' => $onSale,
        ];
    }

    /** Parse a price like "$250.00" / "MX$1,015" / 179 into a float, or null. */
    private function priceNum($raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $clean = preg_replace('/[^0-9.]/', '', (string) $raw);
        return ($clean !== '' && is_numeric($clean)) ? (float) $clean : null;
    }

    /**
     * Live Shopify variant data via <product-url>.js — every size/option with its
     * price, compare_at (sale) price and in-stock flag.
     */
    /**
     * Cheap product enrich for the modal: extra images + description + price from a
     * Shopify product's <url>.js JSON (one light, non-rendered request). No
     * variants, no structured/rendered calls. Returns null for non-Shopify URLs —
     * those rely on the immersive data + the search-card image.
     */
    private function enrichLight(string $url): ?array
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
            $res = Http::timeout(20)->get($target);
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

        $images = array_map(
            fn ($img) => str_starts_with((string) $img, 'http') ? $img : 'https:' . $img,
            $data['images'] ?? []
        );

        // Cheapest-variant price (matches what the search card shows) + LIVE stock.
        $variants = $data['variants'] ?? [];
        $minPrice = null;
        $minCompare = null;
        $anyOnSale = false;
        $anyAvailable = empty($variants); // no variant data → don't claim out-of-stock
        foreach ($variants as $v) {
            $price = isset($v['price']) ? round(((float) $v['price']) / 100, 2) : null;
            $compare = ! empty($v['compare_at_price']) ? round(((float) $v['compare_at_price']) / 100, 2) : null;
            if ($price !== null && ($minPrice === null || $price < $minPrice)) {
                $minPrice = $price;
            }
            if ($compare && $price && $compare > $price) {
                $anyOnSale = true;
                if ($minCompare === null || $compare < $minCompare) {
                    $minCompare = $compare;
                }
            }
            if (! empty($v['available'])) {
                $anyAvailable = true;
            }
        }

        $desc = ! empty($data['description'])
            ? trim(mb_substr(html_entity_decode(strip_tags((string) $data['description']), ENT_QUOTES | ENT_HTML5), 0, 800))
            : null;

        return [
            'images'      => array_slice(array_values(array_filter($images)), 0, 12),
            'description' => $desc ?: null,
            'price'       => $minPrice,
            'was'         => $anyOnSale ? $minCompare : null,
            'on_sale'     => $anyOnSale,
            'available'   => $anyAvailable,
        ];
    }

    /**
     * Detect an UNRELEASED drop from a future date in the product title (e.g.
     * "W2252 … - July 7th"). Drop brands publish the listing — and even flag
     * variants available — days before the storefront page unlocks, so the buy
     * link is a gated dead end until then. The title date is effectively the
     * "available from" date: future → not buyable yet; past → live, show it.
     */
    private function isFutureDrop(?string $title): bool
    {
        if (! $title) {
            return false;
        }
        if (! preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $title, $m)) {
            return false;
        }
        try {
            $today = now()->startOfDay();
            $date = \Illuminate\Support\Carbon::parse($m[1] . ' ' . $m[2] . ' ' . $today->year)->startOfDay();
            return $date->gt($today);
        } catch (\Throwable $e) {
            return false;
        }
    }

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
            $cacheKey = self::shopCacheKey($q, $location, $start);
            $hit = Cache::get($cacheKey);
            if ($hit !== null) {
                $out[$q] = $hit;
            } else {
                $toFetch[$q] = $cacheKey;
            }
        }

        if ($key && $toFetch) {
            // STAMPEDE CONTROL: own each miss with an atomic lock so concurrent
            // identical misses don't all fire (expensive) SerpAPI calls. If another
            // request is already fetching a query, wait briefly for it to fill the
            // cache and reuse that result instead of duplicating the call.
            $locks = []; // query => lock we hold (we must fetch, cache, then release)
            foreach ($toFetch as $q => $cacheKey) {
                $lock = Cache::lock($cacheKey . ':lock', 20);
                if ($lock->get()) {
                    $locks[$q] = $lock; // we own this fetch
                } else {
                    // someone else is fetching it — wait up to 10s for their result.
                    try {
                        $lock->block(10);
                        $lock->release();
                    } catch (\Throwable $e) {
                        // lock wait timed out — fall through and use whatever's cached
                    }
                    $hit = Cache::get($cacheKey);
                    $out[$q] = is_array($hit) ? $hit : [];
                    unset($toFetch[$q]); // don't fetch it ourselves
                }
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
                        // 12s cap: the pool resolves only when ALL queries finish, so a single
                        // slow/hung SerpAPI call would otherwise drag the whole search to its tail
                        // (the 20-25s spikes). Cap it — a laggard fails fast and returns [] while
                        // the other passes (base + priority retailers) still serve results.
                        $reqs[] = $pool->as($q)->timeout(12)->connectTimeout(5)->get('https://serpapi.com/search.json', $params);
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
                    // SerpAPI returns 0 shopping_results NON-DETERMINISTICALLY for the
                    // exact same query (a real store like "rhode" can come back empty one
                    // second and full the next). Never pin an empty pass for the full 30
                    // min — that dead-ends the query for everyone who retries it in that
                    // window (the store-search-returns-nothing bug). Cache real results
                    // for 30 min; cache an empty for only 60s (light stampede protection)
                    // so the very next retry re-queries SerpAPI and self-heals.
                    $ttl = empty($products) ? now()->addSeconds(60) : now()->addMinutes(30);
                    Cache::put($cacheKey, $products, $ttl);
                    $out[$q] = $products;
                } else {
                    $out[$q] = [];
                }
                // Release the stampede lock so any waiters can read the fresh cache.
                if (isset($locks[$q])) {
                    $locks[$q]->release();
                }
            }
        }

        return $out;
    }

    /** Cache key for a SerpAPI google_shopping result (shared with PrimeShoppingCache). */
    public static function shopCacheKey(string $q, ?string $location, int $start = 0): string
    {
        return 'serp_shop:' . md5($q . '|' . (string) $location) . ':' . $start;
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
                // Google flags resale listings ("used", "refurbished"). The shopper
                // extension surfaces this as a Condition filter; everything else
                // treats a missing value as new.
                'condition' => $r['second_hand_condition'] ?? null,
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
    public static function shoppingViaScraperapi(string $q): ?array
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
     * A named brand's OWN catalog, pulled straight from its site — the fix for
     * "I searched a specific store but you showed the resellers." Google Shopping
     * often omits a direct-to-consumer brand's own store and returns only eBay /
     * TikTok Shop / Poshmark listings; this guarantees the real brand shows up.
     *
     * Guesses the brand domain as {slug}.com (correct for the vast majority of DTC
     * brands — dfyne.com, gymshark.com, youngla.com…) and reads its Shopify catalog
     * (deals first). Products are tagged with the requested store name so the
     * ranking layer floats them to the top. Best-effort: a wrong guess, a
     * non-Shopify site (big chains like Target/Best Buy) or a blocked fetch returns
     * [] and we simply fall back to the Google Shopping results. Only reached when
     * the brand was NOT already in Shopping, so it adds no latency to normal search.
     */
    private function brandOwnCatalog(string $store, int $limit): array
    {
        $slug = $this->slugify($store);
        if ($slug === '') {
            return [];
        }
        $products = $this->shopifyProducts('https://' . $slug . '.com', $limit);
        if (empty($products)) {
            return [];
        }
        foreach ($products as &$p) {
            $p['store'] = $store;
        }
        return $products;
    }

    /**
     * Latest products via Shopify products.json (sorted newest first). When
     * $onlySale is set, returns only items whose compare_at_price beats the
     * current price (i.e. real deals).
     */
    private function shopifyProducts(string $origin, int $limit, bool $onlySale = false): array
    {
        // Pull a wide window. Sale items are sparse, AND a big upcoming "drop" can
        // fill the newest pages with future-dated (gated) items we filter out — so
        // fetch deep enough that live products below the drop still backfill.
        $fetch = $onlySale ? min($limit * 5, 200) : min($limit * 3, 150);
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
            $variants = $p['variants'] ?? [];

            // Skip UNRELEASED early-access drops. Stores like YoungLA list future
            // drops in products.json (often with variants flagged available) before
            // the storefront page is public, so the buy link shows "content is
            // protected" — a dead end. They put the release date in the title
            // ("… - July 7th"), so treat a future title-date as "not yet buyable".
            if ($this->isFutureDrop($p['title'] ?? null)) {
                continue;
            }

            // Skip sold-out products too: if we have variant data and NONE are
            // available, drop it (the page would show an out-of-stock dead end).
            $buyable = false;
            foreach ($variants as $v) {
                if (! empty($v['available'])) { $buyable = true; break; }
            }
            if ($variants && ! $buyable) {
                continue;
            }

            $variant = $variants[0] ?? null;
            $price   = isset($variant['price']) ? (float) $variant['price'] : null;
            $compare = isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null;
            $onSale  = $compare && $price && $compare > $price;

            if ($onlySale && ! $onSale) {
                continue;
            }

            // The catalog JSON already carries every product photo — keep a few
            // (not just the first) so the gallery can cycle them on hover.
            $imgs = array_values(array_filter(array_map(
                fn ($im) => is_array($im) ? ($im['src'] ?? null) : (is_string($im) ? $im : null),
                $p['images'] ?? []
            )));

            $out[] = [
                'title'   => $p['title'] ?? null,
                'price'   => $price,
                'was'     => $onSale ? $compare : null,
                'on_sale' => $onSale,
                'image'   => $imgs[0] ?? null,
                'images'  => array_slice($imgs, 0, 5),
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
            // Skip locked / sold-out / unreleased drops (gated product page).
            if ((isset($p['available']) && ! $p['available']) || $this->isFutureDrop($p['title'] ?? null)) {
                continue;
            }
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
