<?php

use App\Http\Middleware\RequireBrowserQueryPrincipal;
use App\Http\Middleware\SecurityHeaders;
use App\Services\TextIngestion\Exceptions\TextIngestionIdempotencyException;
use App\Services\TextIngestion\Exceptions\TextIngestionSourceBusyException;
use App\Services\TextIngestion\Exceptions\TextIngestionWorkflowStartException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
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
        $skipTextIngestionTransforms = static fn (Request $request): bool => $request->is(
            'api/integrations/text-ingestions',
        );

        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->trimStrings(except: [$skipTextIngestionTransforms]);
        $middleware->convertEmptyStringsToNull(except: [$skipTextIngestionTransforms]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'browser-query-principal' => RequireBrowserQueryPrincipal::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'ui/*',
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(
            fn (TextIngestionIdempotencyException $exception): JsonResponse => response()->json([
                'error' => 'text_ingestion_idempotency_conflict',
                'message' => $exception->getMessage(),
            ], 409),
        );
        $exceptions->render(
            fn (TextIngestionSourceBusyException $exception): JsonResponse => response()->json([
                'error' => 'text_ingestion_source_busy',
                'message' => $exception->getMessage(),
            ], 409),
        );
        $exceptions->render(
            fn (TextIngestionWorkflowStartException $exception): JsonResponse => response()->json([
                'error' => 'text_ingestion_workflow_unconfirmed',
                'message' => $exception->getMessage(),
            ], 502),
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*')
                || $request->expectsJson()
        );
    })->create();
