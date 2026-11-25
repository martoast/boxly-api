<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Only a FULL token (abilities ['*'] — every token Boxly issued before 2026-11-25) or a signed-in session passes. A token made
 * for one job (Suite's ['label-scans:arrivals'], see ArrivalsReader) is refused on every other route.
 */
class FullAccessToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! in_array('*', (array) $token->abilities, true)) {
            return response()->json(['success' => false, 'message' => 'This token is limited to its own endpoint.'], 403);
        }
        return $next($request);
    }
}
