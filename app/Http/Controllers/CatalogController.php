<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

// The catalog service's product-page reads that the chat's size/colour picker still uses. The catalog SEARCH
// proxies (search / curate / collection / live-grab) and the SerpAPI gallery engines (google-shop, amazon,
// web-search fan-out, serp-diag, google-product, amazon-product) were removed on 2026-09-28: a product request
// now goes straight to the computer-use engine, which searches the store's own site in a live browser and builds
// the gallery itself (LiveShoppingController, kind=agent). What remains:
//  · productVariants — the live variant read of ONE product page, through our browser (catalog service);
//  · feedProduct — New Balance's variants from Google's product feed (its page cannot be read, see below);
//  · isUnshippableMerchant — the ccTLD merchant guard ProductExtractController (the Shopper panel) still uses.
class CatalogController extends Controller
{
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

    // A MERCHANT THAT CANNOT SHIP TO SAN YSIDRO IS NOT AN OPTION. Boxly's whole mechanism is a US
    // address that receives the purchase, so a storefront outside the US is not a cheaper version of
    // the same offer — it is an offer the customer cannot take. A German retailer was quoted to a
    // customer at $14.63 for a Karl Lagerfeld tee (2026-08-24), and Google Shopping also returns
    // merchants that are not shops at all: a daycare (risingstardaycare.co.za) surfaced for "shorts
    // vital seamless gymshark", a management consultancy (management30.jp) for an Owala bottle.
    //
    // Matched on the COUNTRY-CODE TLD of the merchant name, which is how SerpAPI reports these
    // (`source: "ravir.de"`). Across two months of real searches every merchant on a ccTLD was one
    // of these and every legitimate one was .com (or .myshopify.com), so this drops the bad rows
    // and none of the good ones. A US brand that happens to own a ccTLD domain is not the case
    // here; if one ever is, it belongs in a carried store, not in a Google row.
    private const NON_US_TLDS = [
        'de', 'at', 'ch', 'fr', 'es', 'it', 'nl', 'be', 'pt', 'ie', 'dk', 'se', 'no', 'fi', 'pl', 'cz',
        'gr', 'hu', 'ro', 'ru', 'tr', 'ua', 'uk', 'co.uk', 'eus', 'jp', 'cn', 'kr', 'hk', 'sg', 'in',
        'au', 'com.au', 'nz', 'co.nz', 'za', 'co.za', 'ae', 'il', 'br', 'com.br', 'ar', 'com.ar', 'cl',
        'pe', 'ca', 'mx', 'com.mx',
    ];

    /** Is this merchant a storefront the customer cannot buy from for a US delivery? */
    public static function isUnshippableMerchant(?string $merchant): bool
    {
        $m = mb_strtolower(trim((string) $merchant));
        if ($m === '' || ! str_contains($m, '.')) {
            return false;   // a plain name ("Amazon", "Red Tool Store") says nothing about country
        }
        // Only the HOST part: a merchant is reported as a bare domain, never a URL with a path.
        $host = preg_replace('/^https?:\/\//', '', $m);
        $host = rtrim(explode('/', $host)[0], '.');
        foreach (self::NON_US_TLDS as $tld) {
            if (str_ends_with($host, '.' . $tld)) {
                return true;
            }
        }
        return false;
    }

    /**
     * VARIANTS FOR A STORE WHOSE OWN PAGE WE CANNOT READ.
     *
     * New Balance answers every server-side fetch with 403 (edge bot protection, not the
     * user agent — a full browser header set is refused too), and in the headless browser
     * the page loads but exposes an EMPTY accessibility tree: three reads on 2026-09-13,
     * at 7s, +3s and +8s, all returned "this page exposes no content". So there is no
     * amount of retrying that reads it, and the modal fell back to a mirror whose colours
     * carry no photos — pick a colour, nothing moves.
     *
     * Google's own product feed knows the product. google_product is dead ("no longer
     * offered by Google"), but google_immersive_product carries the colour and size axes
     * WITH per-option availability, and each colour links to itself so its photo can be
     * fetched. That costs a call per colour, which is why the whole answer is cached for
     * six hours rather than assembled while a shopper waits twice.
     */
    public function feedProduct(Request $request)
    {
        $query = trim((string) $request->input('query'));
        $brand = trim((string) $request->input('brand'));
        $withImages = $request->boolean('with_images', true);
        if ($query === '') {
            return response()->json(['variants' => [], 'axes' => [], 'error' => 'need query'], 200);
        }
        $key = (string) config('services.serpapi.key');
        if ($key === '') {
            return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_not_configured'], 200);
        }

        $cacheKey = 'feedprod:' . md5(mb_strtolower($query . '|' . $brand . '|' . ($withImages ? '1' : '0')));
        if ($hit = Cache::get($cacheKey)) { return response()->json($hit, 200); }

        try {
            $search = Http::timeout(20)->get('https://serpapi.com/search.json', [
                'engine' => 'google_shopping', 'q' => $query, 'gl' => 'us', 'hl' => 'en', 'api_key' => $key,
            ]);
            if (! $search->ok()) { return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_error'], 200); }
            $rows = $search->json('shopping_results') ?? [];
            // Prefer the brand's OWN listing: its colour names are the ones the shopper
            // sees on the store, and a reseller's row names them differently.
            // NEVER QUOTE A SIZE-SPECIFIC LISTING AS THE WHOLE PRODUCT. Google lists some
            // items per size, so a match can be "Defy Leggings L" or "Dynamic Leggings XL"
            // — a row whose axes describe that ONE size. Measured on DFYNE 2026-09-13: it
            // answered with two sizes for a brand that sells more, which would tell a
            // shopper their size does not exist. That is worse than admitting we could not
            // read the page, so those rows are skipped and a real parent listing preferred.
            $sizeSuffix = '/\s(?:XXS|XS|S|M|L|XL|XXL|XXXL|\d{1,2}\.5)$/i';
            $pick = null;
            $fallback = null;
            foreach ($rows as $r) {
                if (empty($r['immersive_product_page_token'])) { continue; }
                $title = trim((string) ($r['title'] ?? ''));
                if ($title !== '' && preg_match($sizeSuffix, $title)) { continue; }
                $src = mb_strtolower((string) ($r['source'] ?? ''));
                if ($brand !== '' && str_contains($src, mb_strtolower($brand))) { $pick = $r; break; }
                $fallback = $fallback ?: $r;
            }
            $pick = $pick ?: $fallback;
            if (! $pick) { return response()->json(['variants' => [], 'axes' => [], 'error' => 'no_feed_match'], 200); }

            $imm = Http::timeout(25)->get('https://serpapi.com/search.json', [
                'engine' => 'google_immersive_product', 'page_token' => $pick['immersive_product_page_token'], 'api_key' => $key,
            ]);
            if (! $imm->ok()) { return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_error'], 200); }
            $payload = $this->normalizeFeedProduct($imm->json(), $pick, $key, $withImages);
            Cache::put($cacheKey, $payload, now()->addHours(6));
            return response()->json($payload, 200);
        } catch (\Throwable $e) {
            return response()->json(['variants' => [], 'axes' => [], 'error' => 'serpapi_unreachable'], 200);
        }
    }

    /** google_immersive_product -> the shape /catalog/product-variants returns. */
    private function normalizeFeedProduct(array $json, array $row, string $key, bool $withImages): array
    {
        $pr = $json['product_results'] ?? [];
        $axes = [];
        $variants = [];
        $selected = [];
        $swatches = [];
        $colourLinks = [];
        foreach (($pr['variants'] ?? []) as $dim) {
            $name = trim((string) ($dim['title'] ?? ''));
            if ($name === '') { continue; }
            $values = [];
            foreach (($dim['items'] ?? []) as $opt) {
                $v = trim((string) ($opt['name'] ?? ''));
                // Google prepends an "Any Color" / "Any Size" pseudo-option. It is not a
                // thing anyone can buy, and offering it as a chip would let a shopper
                // "choose" without choosing.
                if ($v === '' || preg_match('/^any\s/i', $v)) { continue; }
                $values[] = $v;
                if (! empty($opt['selected'])) { $selected[$name] = $v; }
                // Availability is per option here, which the mirror never knew.
                $available = array_key_exists('available', $opt) ? filter_var($opt['available'], FILTER_VALIDATE_BOOLEAN) : null;
                $variants[] = [
                    'key' => $name . ':' . $v,
                    'options' => [$name => $v],
                    'available' => $available,
                    'price' => null,
                ];
                if ($withImages && stripos($name, 'col') !== false && count($colourLinks) < 6 && ! empty($opt['serpapi_link'])) {
                    $colourLinks[$v] = (string) $opt['serpapi_link'];
                }
            }
            if ($values) {
                $lower = mb_strtolower($name);
                $kind = str_contains($lower, 'siz') ? 'size' : (str_contains($lower, 'col') ? 'color' : 'other');
                $axis = ['name' => $name, 'kind' => $kind, 'values' => array_values(array_unique($values))];
                $axes[] = $axis;
            }
        }
        // ONE COLOUR AT A TIME WAS TOO SLOW TO SURVIVE. Six colours fetched serially, on top
        // of the search and the immersive call, ran the request past its own timeout and the
        // whole answer came back as serpapi_unreachable — so the shopper got nothing rather
        // than a slow something. Ask for them together; the pool costs about what one does.
        if ($colourLinks) {
            $swatches = $this->feedColourImages($colourLinks, $key);
            foreach ($axes as $i => $ax) {
                if (($ax['kind'] ?? '') === 'color' && $swatches) { $axes[$i]['swatches'] = $swatches; }
            }
        }

        // Hang each colour's photo on its variant row too, so the mirror can remember it
        // and the hero can swap without another feed call.
        foreach ($variants as $i => $v) {
            $c = $v['options']['Color'] ?? $v['options']['color'] ?? null;
            if ($c && isset($swatches[$c])) { $variants[$i]['image'] = $swatches[$c]; }
        }
        $images = array_values(array_filter(array_slice($pr['thumbnails'] ?? [], 0, 8), 'is_string'));
        return [
            'product' => [
                'title' => $pr['title'] ?? ($row['title'] ?? null),
                'url' => $row['link'] ?? ($row['product_link'] ?? null),
                'price' => isset($row['extracted_price']) ? (float) $row['extracted_price'] : null,
                'list_price' => null,
                'image' => $images[0] ?? ($row['thumbnail'] ?? null),
                'images' => $images,
            ],
            // Google lists each axis independently — a colour row carries no size — which
            // is exactly what axes_independent means to the picker.
            'axes_independent' => true,
            'axes' => $axes,
            'variants' => $variants,
            'selected' => $selected ?: null,
            'checked_at' => now()->toIso8601String(),
            'source' => 'feed-immersive',
        ];
    }

    /**
     * Every colour's own photo, fetched together. Each link re-runs the immersive query
     * with that colour selected, so its first thumbnail is that colour. A colour that does
     * not answer is simply left without one — the chip still works, it just shows no photo,
     * which is what the whole product looked like before.
     */
    private function feedColourImages(array $links, string $key): array
    {
        $names = array_keys($links);
        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($l) => $pool->timeout(15)->get($l . '&api_key=' . urlencode($key)),
                array_values($links),
            ));
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($responses as $i => $res) {
            if (! ($res instanceof \Illuminate\Http\Client\Response) || ! $res->ok()) { continue; }
            $th = $res->json('product_results.thumbnails') ?? [];
            if (is_array($th) && isset($th[0]) && is_string($th[0])) { $out[$names[$i]] = $th[0]; }
        }
        return $out;
    }
}
