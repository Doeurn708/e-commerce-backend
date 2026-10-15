<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS is terminated by Railway's edge proxy, which forwards the original
        // scheme/port/client IP in X-Forwarded-*. Trusting the proxy (the app is
        // only reachable through it) lets Symfony read those headers, so
        // request()->isSecure() and Secure session cookies work in production
        // while plain HTTP on localhost keeps working unchanged.
        $middleware->trustProxies(at: '*');

        // `role:admin` guards the write/admin endpoints declared in routes/api.php.
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
