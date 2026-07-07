<?php

use App\Http\Middleware\RequireOperatorAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\AuthorizationGrantException;
use App\Services\SpecV2\Exceptions\CorpusNotFoundException;
use App\Services\SpecV2\Exceptions\GroupNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Exceptions\InvalidGroupIdentifierException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/internal_api.php',
        web: __DIR__.'/../routes/web_ui.php',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'operator' => RequireOperatorAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'ui/*',
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $exception): bool => $request->is('api/*') || $request->expectsJson()
        );
        $exceptions->render(function (ApplicationNotFoundException|InvalidGroupIdentifierException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        });
        $exceptions->render(function (AccessDeniedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        });
        $exceptions->render(function (HeapNotFoundException|GroupNotFoundException|CorpusNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        });
        $exceptions->render(function (AuthorizationGrantException $exception) {
            $status = str_contains($exception->getMessage(), 'was not found.') ? 404 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        });
    })->create();
