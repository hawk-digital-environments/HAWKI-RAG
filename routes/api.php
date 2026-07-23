<?php

declare(strict_types=1);

use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\Document\UnifiedDocumentController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\Health\HawkiRagSystemGateController;
use App\Http\Controllers\Health\PipelineHealthController;
use App\Http\Controllers\Health\RagHealthController;
use App\Http\Controllers\Health\RagMonitorController;
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UploadedSourceDocumentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Canonical Application API
|--------------------------------------------------------------------------
| Laravel mounts this file under /api. Both the Svelte browser shell and
| external API clients use these routes, so each operation has one canonical
| path and one middleware policy. Stateful first-party requests receive
| session and CSRF handling; requests with bearer tokens remain stateless.
*/

/*
|--------------------------------------------------------------------------
| Authentication: Browser Query Session
|--------------------------------------------------------------------------
| A trusted first-party browser may exchange a query-capable bearer token for
| the dedicated HttpOnly query session. This is a UI authentication handshake,
| not a general token endpoint, so it is intentionally omitted from OpenAPI.
*/
Route::post('/auth/session', [BrowserSessionController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:hawki-api'])
    ->defaults('openapi', false);

/*
|--------------------------------------------------------------------------
| Retrieval Domain
|--------------------------------------------------------------------------
| Query routes accept either a bearer token with query access or the dedicated
| browser query session. The principal service also derives the allowed
| dataset scope server-side before a request reaches the RAG bridge.
*/
Route::middleware(['browser-query-principal', 'throttle:hawki-api'])->group(function (): void {
    Route::get('/query/datasets', [HawkiRagProxyController::class, 'datasets']);
    Route::post('/query', [HawkiRagProxyController::class, 'query'])
        ->middleware('throttle:hawki-rag-query');
});

/*
|--------------------------------------------------------------------------
| Operator API Boundary
|--------------------------------------------------------------------------
| The operator middleware accepts an authorized browser session or an
| operator-capable bearer token. Domain-specific upload and destructive
| throttles below are added on top of the shared API rate limit.
*/
Route::middleware(['operator', 'throttle:hawki-api'])->group(function (): void {
    /*
    |----------------------------------------------------------------------
    | Platform Connectivity
    |----------------------------------------------------------------------
    | Unlike the public /up liveness check, ping confirms that the caller can
    | reach the authenticated operator API boundary.
    */
    Route::get('/ping', static fn (): JsonResponse => response()->json(['pong' => true]));

    /*
    |----------------------------------------------------------------------
    | Runtime Settings Domain
    |----------------------------------------------------------------------
    | Reads and updates the operator-managed converter and model defaults.
    | These routes currently belong to the Svelte settings UI rather than the
    | published external API contract.
    */
    Route::get('/settings/config', [SettingsController::class, 'show'])
        ->defaults('openapi', false);
    Route::put('/settings/config', [SettingsController::class, 'update'])
        ->middleware('throttle:hawki-destructive')
        ->defaults('openapi', false);

    /*
    |----------------------------------------------------------------------
    | Dataset Domain
    |----------------------------------------------------------------------
    | Dataset routes manage searchable dataset metadata. Storage cleanup is
    | separated from metadata reads and creation because it deletes Qdrant and
    | Neo4j data and therefore receives the destructive-operation throttle.
    */
    Route::prefix('datasets')->group(function (): void {
        Route::get('/', [DatasetController::class, 'index']);
        Route::post('/', [DatasetController::class, 'store']);
        Route::get('/{datasetId}', [DatasetController::class, 'show']);
        Route::delete('/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])
            ->middleware('throttle:hawki-destructive');
    });

    /*
    |----------------------------------------------------------------------
    | Document Domain
    |----------------------------------------------------------------------
    | Provides document browsing plus the unified create, batch, update, and
    | delete lifecycle. Ingestion writes use the upload throttle; deletion is
    | destructive. The source download route serves only validated uploads.
    */
    Route::prefix('documents')->group(function (): void {
        Route::get('/', [DocumentBrowserController::class, 'index']);
        Route::post('/', [UnifiedDocumentController::class, 'store'])
            ->middleware('throttle:hawki-upload');
        Route::post('/batch', [UnifiedDocumentController::class, 'storeBatch'])
            ->middleware('throttle:hawki-upload');
        Route::get('/uploads/download', UploadedSourceDocumentController::class)
            ->name('documents.uploads.download')
            ->defaults('openapi', false);
        Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
        Route::put('/{documentId}', [UnifiedDocumentController::class, 'update'])
            ->middleware('throttle:hawki-upload');
        Route::delete('/{documentId}', [UnifiedDocumentController::class, 'destroy'])
            ->middleware('throttle:hawki-destructive');
    });

    /*
    |----------------------------------------------------------------------
    | Scraper Domain
    |----------------------------------------------------------------------
    | Proxies crawler task discovery and job lifecycle operations used by the
    | pipeline UI. These integration-specific contracts remain hidden from
    | OpenAPI until the external crawler protocol is treated as stable.
    */
    Route::prefix('scraper')->group(function (): void {
        Route::get('/jobs', [ScrapeController::class, 'getCrawlerJobs'])
            ->defaults('openapi', false);
        Route::get('/tasks', [ScrapeController::class, 'getCrawlerTasks'])
            ->defaults('openapi', false);
        Route::post('/tasks/start', [ScrapeController::class, 'startCrawlerTask'])
            ->middleware('throttle:hawki-upload')
            ->defaults('openapi', false);
        Route::get('/status/{jobId}', [ScrapeController::class, 'getCrawlerStatus'])
            ->defaults('openapi', false);
        Route::post('/jobs/{jobId}/cancel', [ScrapeController::class, 'cancelCrawlerJob'])
            ->middleware('throttle:hawki-destructive')
            ->defaults('openapi', false);
        Route::post('/jobs/{jobId}/pause', [ScrapeController::class, 'pauseCrawlerJob'])
            ->middleware('throttle:hawki-destructive')
            ->defaults('openapi', false);
        Route::post('/jobs/{jobId}/resume', [ScrapeController::class, 'resumeCrawlerJob'])
            ->middleware('throttle:hawki-destructive')
            ->defaults('openapi', false);
    });

    /*
    |----------------------------------------------------------------------
    | Pipeline Domain
    |----------------------------------------------------------------------
    | Owns execution status, task orchestration, file ingestion, recovery, and
    | pipeline-specific health. Keeping every /pipeline route in one prefix
    | makes the domain boundary visible and prevents related routes drifting.
    */
    Route::prefix('pipeline')->group(function (): void {
        // Lightweight status lookup used while the browser follows one job.
        Route::get('/status/{jobId}', [PipelineStatusController::class, 'show'])
            ->defaults('openapi', false);

        // Task routes expose the aggregate state, stage evidence, and controls
        // for a complete scrape -> convert -> ingest execution.
        Route::prefix('tasks')->group(function (): void {
            Route::get('/', [PipelineTaskController::class, 'index']);
            Route::post('/start', [PipelineTaskController::class, 'start'])
                ->middleware('throttle:hawki-upload');
            Route::get('/{taskId}', [PipelineTaskController::class, 'show']);
            Route::get('/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
            Route::get('/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
            Route::get('/{taskId}/events', [PipelineTaskController::class, 'events']);
            Route::get('/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
            Route::get('/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
            Route::post('/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
            Route::post('/{taskId}/retry', [PipelineTaskController::class, 'retry'])
                ->middleware('throttle:hawki-destructive')
                ->defaults('openapi', false);
            Route::post('/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs'])
                ->middleware('throttle:hawki-destructive')
                ->defaults('openapi', false);
            Route::post('/{taskId}/cancel', [PipelineTaskController::class, 'cancel'])
                ->middleware('throttle:hawki-destructive');
            Route::delete('/{taskId}', [PipelineTaskController::class, 'destroy'])
                ->middleware('throttle:hawki-destructive');
        });

        // File ingestion consumes storage and worker capacity, so it receives
        // the upload-specific rate limit before starting a workflow.
        Route::post('/controller/files', [PipelineControlController::class, 'uploadFile'])
            ->middleware('throttle:hawki-upload');

        // Recovery mutations can requeue large amounts of work. The stricter
        // destructive throttle prevents accidental retry storms.
        Route::prefix('recovery')->group(function (): void {
            Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
            Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])
                ->middleware('throttle:hawki-destructive');
            Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])
                ->middleware('throttle:hawki-destructive');
            Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll'])
                ->middleware('throttle:hawki-destructive');
            Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])
                ->middleware('throttle:hawki-destructive');
            Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])
                ->middleware('throttle:hawki-destructive');
        });

        // Pipeline health is detailed operator diagnostics, not public
        // liveness; infrastructure probes must use /up.
        Route::get('/health', [PipelineHealthController::class, 'show']);
    });

    /*
    |----------------------------------------------------------------------
    | Health and Monitoring Domain
    |----------------------------------------------------------------------
    | Reports RAG bridge health, runtime monitoring, and the combined system
    | gate. These detailed payloads remain operator-only; /up is the public
    | liveness endpoint for infrastructure probes.
    */
    Route::get('/rag/health', [RagHealthController::class, 'show']);
    Route::get('/rag/monitor', [RagMonitorController::class, 'show']);
    Route::get('/health/system-gate', [HawkiRagSystemGateController::class, 'show']);

    /*
    |----------------------------------------------------------------------
    | Vector Storage Domain
    |----------------------------------------------------------------------
    | Reports RAG/Qdrant statistics and allows explicit collection cleanup.
    | Collection deletion is isolated behind the destructive throttle.
    */
    Route::get('/rag/stats', [RagStatsController::class, 'show']);
    Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])
        ->middleware('throttle:hawki-destructive');

    /*
    |----------------------------------------------------------------------
    | Knowledge Graph Domain
    |----------------------------------------------------------------------
    | Supports graph exploration, semantic search, expansion, and saved views.
    | Clearing the persisted Neo4j graph is distinct from clearing a browser
    | view and therefore receives destructive-operation throttling.
    */
    Route::prefix('rag/neo4j')->group(function (): void {
        Route::get('/graph/overview', [RagGraphController::class, 'overview']);
        Route::get('/graph/search', [RagGraphController::class, 'search']);
        Route::get('/graph/semantic-search', [RagGraphController::class, 'semanticSearch']);
        Route::get('/graph/node', [RagGraphController::class, 'node']);
        Route::post('/graph/expand', [RagGraphController::class, 'expand']);
        Route::post('/graph/clear-view', [RagGraphController::class, 'clearView']);
        Route::get('/graph/snapshots', [RagGraphController::class, 'snapshots']);
        Route::post('/graph/snapshots', [RagGraphController::class, 'saveSnapshot']);
        Route::get('/graph/snapshots/{id}', [RagGraphController::class, 'loadSnapshot']);
        Route::delete('/graph/snapshots/{id}', [RagGraphController::class, 'deleteSnapshot']);
        Route::post('/clear', [RagGraphController::class, 'clearNeo4j'])
            ->middleware('throttle:hawki-destructive');
    });
});
