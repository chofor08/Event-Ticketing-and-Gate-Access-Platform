<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\prettyJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'role' => EnsureUserRole::class,
            'idempotency' => EnsureIdempotency::class,
            'pretty_json' => prettyJson::class,
        ]);

        $middleware->appendToGroup('api', [
            prettyJson::class,
        ]);

        $middleware->preventRequestForgery(except: [
            'api/stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
