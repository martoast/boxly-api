<?php

namespace App\Http\Middleware;

use App\Services\BoxlyBeta;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boxly Lab: the live-carts product (cart, store sync, automatic quotes) is
 * reachable only by allowlisted internal testers while it is evaluated in
 * production. Everyone else gets a plain 404, as if it did not exist.
 */
class EnsureBoxlyLab
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! BoxlyBeta::allows($request->user())) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return $next($request);
    }
}
