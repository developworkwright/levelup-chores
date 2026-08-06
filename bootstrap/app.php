<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SyncStreak;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'sync-streak' => SyncStreak::class,
        ]);

        // Laravel Cloud terminates TLS at its edge load balancer, so trust it
        // to report the original scheme/IP (needed for secure cookies + rate
        // limiting by real client IP).
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : explode(',', $trustedProxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
