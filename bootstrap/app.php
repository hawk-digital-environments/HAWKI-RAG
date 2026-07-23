<?php

use App\Http\Middleware\RequireAdminAccess;
use App\Http\Middleware\RequireBrowserQueryPrincipal;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'browser-query-principal' => RequireBrowserQueryPrincipal::class,
            'admin' => RequireAdminAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'ui/*',
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $exception): bool => $request->is('api/*')
                || $request->expectsJson()
        );
    })->create();
