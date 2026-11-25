<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who may read /label-scans/arrivals: a token issued with the 'label-scans:arrivals' ability (Suite's 5 PM arrival WhatsApp,
 * 2026-11-25 — that token can read nothing else, see FullAccessToken), or an admin.
 */
class ArrivalsReader
{
    const ABILITY = 'label-scans:arrivals';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $scoped = $token instanceof PersonalAccessToken && in_array(self::ABILITY, (array) $token->abilities, true);
        if (! $user || (! $scoped && $user->role !== User::ROLE_ADMIN)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
