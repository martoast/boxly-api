<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /shopping/* namespace. Allows admins (full visibility) and
 * employees on the shopping team (Velonie). Warehouse employees and
 * customers are blocked.
 */
class ShoppingEmployeeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canManageShopping()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Shopping team access required.',
            ], 403);
        }

        return $next($request);
    }
}
