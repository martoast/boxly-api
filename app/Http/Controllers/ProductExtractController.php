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

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'source_url' => $url,
                'store' => $store,
                'currency' => 'USD',
            ], $product),
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
        if (! preg_match('#^(https?://[^/]+/.*/products/[^/?#]+)#', $url, $m)) {
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
}
