<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
}
