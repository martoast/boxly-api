<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets a public Cache-Control header on successful GET responses so
 * Cloudflare (and any browser/CDN cache in front) can edge-cache them.
 *
 * Without this, the framework's default for API responses is
 * `Cache-Control: no-cache, private` (because Sanctum session
 * middleware assumes responses may carry per-user data). Public
 * /store/* endpoints don't carry any user state, so we override.
 *
 * Usage in routes/api.php:
 *
 *   Route::middleware('edge.cache:300,3600,86400')->group(function () {
 *       Route::get('/store/products', ...);
 *       ...
 *   });
 *
 * Args (all in seconds):
 *   maxAge  — how long the browser caches privately (default 300, 5 min).
 *             Short on purpose so admins viewing the public site see
 *             their changes within minutes on their own browser.
 *   sMaxAge — how long Cloudflare / shared caches hold the response
 *             (default 3600, 1 hour). This is what gives most visitors
 *             a ~30ms response from a Mexico edge node.
 *   swr     — stale-while-revalidate window (default 86400, 24 hours).
 *             During this window, edge serves the stale copy while
 *             revalidating with the origin in background — visitors
 *             never wait on a cache miss after the first one.
 *
 * Only acts on GET. Any other method (POST/PUT/DELETE/PATCH) passes
 * through untouched so writes never get cached.
 */
class EdgeCacheHeaders
{
    public function handle(Request $request, Closure $next, int $maxAge = 300, int $sMaxAge = 3600, int $swr = 86400): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful()) {
            $response->headers->set(
                'Cache-Control',
                sprintf(
                    'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
                    $maxAge,
                    $sMaxAge,
                    $swr,
                ),
            );

            // Sanctum's stateful middleware sets a Vary: Cookie header
            // which would otherwise prevent Cloudflare from caching
            // (since each user's cookies differ). For public reads we
            // know cookies don't affect output, so strip it.
            $response->headers->remove('Vary');
        }

        return $response;
    }
}
