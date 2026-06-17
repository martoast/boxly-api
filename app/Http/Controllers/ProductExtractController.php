<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
     * Universal product search across the ENTIRE US market via Google Shopping
     * (ScraperAPI structured endpoint). Works for ANY store/brand regardless of
     * platform (Shopify, headless, JS-rendered, Cloudflare-protected) because
     * Google already crawled them. Returns normalized products with embedded
     * (base64) images, prices, and the source store for each.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'store' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:40',
        ]);

        $key = config('services.scraperapi.key');
        if (! $key) {
            return response()->json(['success' => false, 'message' => 'Search not configured.'], 503);
        }

        $limit = $validated['limit'] ?? 16;
        $store = trim($validated['store'] ?? '');
        // Bias the query toward the store when one is specified.
        $q = $store !== '' ? $store . ' ' . $validated['query'] : $validated['query'];

        try {
            // Google must be scraped through advanced/residential proxies —
            // plain and premium modes 500 intermittently (~55s), while
            // ultra_premium returns reliably (~20-25s). Costs more credits, but
            // this is a core feature; upgrade the ScraperAPI plan for volume.
            $res = Http::timeout(40)->get('https://api.scraperapi.com/structured/google/shopping', [
                'api_key'       => $key,
                'query'         => $q,
                'country'       => 'us',
                'ultra_premium' => 'true',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Search failed.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(['success' => false, 'message' => 'Search failed.'], 502);
        }

        $results = $res->json('shopping_results') ?? [];
        if (! is_array($results)) {
            $results = [];
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
                // Google links are catalog views, not direct buy URLs — give a
                // viewable Shopping search link; the real merchant URL gets
                // resolved at order time via extract_product.
                'url'   => 'https://www.google.com/search?tbm=shop&q=' . urlencode($title),
            ];
        }

        // When a store was specified, surface that store's items first.
        if ($store !== '') {
            $needle = mb_strtolower($store);
            usort($products, function ($a, $b) use ($needle) {
                $am = str_contains(mb_strtolower((string) ($a['store'] ?? '')), $needle) ? 0 : 1;
                $bm = str_contains(mb_strtolower((string) ($b['store'] ?? '')), $needle) ? 0 : 1;
                return $am <=> $bm;
            });
        }

        return response()->json([
            'success' => true,
            'data'    => ['query' => $q, 'products' => array_slice($products, 0, $limit)],
        ]);
    }

    private function fetch(string $url): ?string
    {
        $key = config('services.scraperapi.key');
        try {
            if ($key) {
                $res = Http::timeout(45)->get('https://api.scraperapi.com', [
                    'api_key' => $key,
                    'url' => $url,
                    'render' => 'false',
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
                ? 'https://api.scraperapi.com?' . http_build_query(['api_key' => $key, 'url' => $jsUrl])
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
        $sale  = (bool) ($validated['sale'] ?? false);

        if ($sale) {
            $products = $this->shopifyProducts($origin, $limit, true);
        } elseif (! empty($validated['query'])) {
            $products = $this->shopifySearch($origin, $validated['query'], $limit);
        } else {
            $products = $this->shopifyProducts($origin, $limit);
        }

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
