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
        $params = array_filter([
            'q' => $request->query('q') ?: $request->query('query'),
            'store' => $request->query('store'),
            'sale' => $request->boolean('sale') ? '1' : null,
            'max' => $request->query('max') ?: $request->query('max_price'),
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
            'products' => $data['products'] ?? [],
        ]);
    }
}
