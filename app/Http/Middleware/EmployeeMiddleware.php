<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->canManageWarehouse()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Employee access required.'
            ], 403);
        }

        return $next($request);
    }
}
