<?php

use App\Http\Middleware\JsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '/',
        // routes/console.php was never loaded, so nothing in it ran and
        // schedule:list reported no tasks at all. The product index upkeep
        // (boxly:index-warm) is defined there.
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'stripe/*',
            'products/*',         // public, server-to-server (AI assistant)
            'search-events',      // public best-effort analytics logging
        ]);
        $middleware->append(JsonResponse::class);
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'employee' => \App\Http\Middleware\EmployeeMiddleware::class,
            'shopping' => \App\Http\Middleware\ShoppingEmployeeMiddleware::class,
            'edge.cache' => \App\Http\Middleware\EdgeCacheHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
