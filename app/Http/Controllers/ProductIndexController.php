<?php

namespace App\Http\Controllers;

use App\Models\ProductIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Read and write the product index.
 *
 * Called SERVER-TO-SERVER by the Nuxt panel endpoint, never by the extension
 * and never by a browser. That distinction is the whole security model here:
 *
 * The other /products/* routes are public because they only ever READ from
 * third parties. This one WRITES the answer that gets shown to every shopper on
 * a product, so a public write endpoint would let anyone put a fabricated price
 * — or a link to anywhere — in front of every Boxly customer looking at that
 * item. A shared secret keeps it to our own server.
 *
 * The secret is required for reads too. There is nothing sensitive in a resolved
 * product, but an open read endpoint hands out our accumulated index for free,
 * and it costs nothing to close.
 */
class ProductIndexController extends Controller
{
    /**
     * Reject anything that isn't our Nuxt server.
     *
     * Unset secret = feature off, not feature open. A missing env var must never
     * silently produce a writable public endpoint — that is how a config gap
     * becomes an incident.
     */
    private function denied(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $expected = (string) config('services.product_index.secret', env('PRODUCT_INDEX_SECRET', ''));
        if ($expected === '') {
            return response()->json(['error' => 'product index not configured'], 503);
        }
        $given = (string) $request->header('X-Boxly-Index-Secret', '');
        // Constant-time: a plain === leaks the secret one byte at a time to
        // anyone willing to measure.
        if (! hash_equals($expected, $given)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return null;
    }

    /** Look up a resolved product. 404 when we've never seen it — the caller falls through. */
    public function show(Request $request)
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $key = trim((string) $request->input('key', ''));
        if ($key === '') {
            return response()->json(['error' => 'key required'], 422);
        }

        $row = ProductIndex::where('canonical_key', $key)->first();
        if (! $row) {
            return response()->json(['hit' => false], 404);
        }

        // Popularity, for stage 4's refresh ordering. Not a counter worth a
        // transaction — an undercount costs a slightly worse refresh priority.
        ProductIndex::where('id', $row->id)->increment('hits');

        return response()->json([
            'hit' => true,
            'payload' => $row->payload,
            // The caller decides what is too old. Age policy belongs with the
            // panel, which knows whether it is about to claim a saving.
            'resolved_at' => optional($row->resolved_at)->toIso8601String(),
            // resolved_at->diffInSeconds(now()), NOT now()->diffInSeconds(resolved_at).
            // Carbon 3 returns a SIGNED difference, so the reversed form gives a
            // large negative number for an old row — and the caller's
            // `age > maxAge` check then treats every stale price as fresh. That
            // is the one failure this whole cache is not allowed to have.
            'age_seconds' => $row->resolved_at ? (int) round($row->resolved_at->diffInSeconds(now())) : null,
        ]);
    }

    /** Remember a resolved product, replacing whatever we had. */
    public function store(Request $request)
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $data = $request->validate([
            'key' => 'required|string|max:191',
            'payload' => 'required|array',
            'identifiers' => 'nullable|array',
            'title' => 'nullable|string|max:300',
            'brand' => 'nullable|string|max:120',
            'variant' => 'nullable|string|max:120',
            'image' => 'nullable|string|max:2000',
            'store' => 'nullable|string|max:120',
        ]);

        try {
            ProductIndex::updateOrCreate(
                ['canonical_key' => $data['key']],
                [
                    'identifiers' => $data['identifiers'] ?? null,
                    'title' => $data['title'] ?? null,
                    'brand' => $data['brand'] ?? null,
                    'variant' => $data['variant'] ?? null,
                    'image' => $data['image'] ?? null,
                    'store' => $data['store'] ?? null,
                    'payload' => $data['payload'],
                    'resolved_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Never fail a shopper's panel over a cache write.
            Log::warning('[product-index] write failed', ['key' => $data['key'], 'error' => $e->getMessage()]);

            return response()->json(['ok' => false], 200);
        }

        return response()->json(['ok' => true]);
    }
}
