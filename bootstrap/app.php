<?php

use App\Http\Middleware\JsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '/',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        // The API sits behind Cloudflare (TLS terminated at the edge), so the
        // connection Laravel sees is plain http. Trust the proxy's forwarded
        // headers so url()/redirect() generate https:// URLs — otherwise
        // redirects come out as insecure http:// links and the browser blocks
        // them as mixed content.
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'stripe/*',
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
