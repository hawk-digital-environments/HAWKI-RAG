<?php

declare(strict_types=1);

use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetIngestionGrantController;
use App\Http\Controllers\DatasetQueryGrantController;
use App\Http\Controllers\Document\ManagedDocumentDeletionEventController;
use App\Http\Controllers\Document\ManagedDocumentWriterController;
use App\Http\Controllers\Document\UnifiedDocumentController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\Graph\ClearGraphViewController;
use App\Http\Controllers\Graph\ClearNeo4jController;
use App\Http\Controllers\Graph\GraphOpsController;
use App\Http\Controllers\Graph\GraphSnapshotController;
use App\Http\Controllers\Health\HawkiRagSystemGateController;
use App\Http\Controllers\Health\PipelineHealthController;
use App\Http\Controllers\Health\RagHealthController;
use App\Http\Controllers\Health\RagMonitorController;
use App\Http\Controllers\Integration\TextIngestionController;
use App\Http\Controllers\Integration\TextIngestionDeletionController;
use App\Http\Controllers\Pipeline\PipelineWorkerEventController;
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UploadedSourceDocumentController;
use App\Http\Middleware\CrawlerIngestionBoundary;
use App\Http\Middleware\LimitTextIngestionRequestSize;
use App\Http\Middleware\RequireManagedMutationPrincipal;
use App\Http\Middleware\RequireTextIngestionToken;
use App\Http\Middleware\VerifyPipelineWorkerSignature;
use App\Services\Dataset\RequireDatasetManager;
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
| Retrieval Domain
|--------------------------------------------------------------------------
| Query routes use an explicit query-capable bearer principal when supplied.
| Credential-free requests resolve only when exactly one active user exists.
| The principal service derives the allowed dataset scope server-side before
| a request reaches the RAG bridge.
*/
Route::middleware('browser-query-principal')->group(function (): void {
    Route::get('/query/datasets', [HawkiRagProxyController::class, 'datasets']);
    Route::post('/query', [HawkiRagProxyController::class, 'query']);
});

/*
|--------------------------------------------------------------------------
| Internal Pipeline Worker Boundary
|--------------------------------------------------------------------------
| Workers authenticate with an exact-body HMAC instead of a browser or user
| principal. Laravel validates and owns every resulting metadata mutation.
*/
Route::post('/internal/pipeline/worker-events', PipelineWorkerEventController::class)
    ->middleware(VerifyPipelineWorkerSignature::class)
    ->defaults('openapi', false);

Route::post('/internal/pipeline/managed-deletion-events', ManagedDocumentDeletionEventController::class)
    ->middleware(VerifyPipelineWorkerSignature::class)
    ->defaults('openapi', false);

Route::post('/internal/pipeline/managed-writers', ManagedDocumentWriterController::class)
    ->middleware(VerifyPipelineWorkerSignature::class)
    ->defaults('openapi', false);

/*
|--------------------------------------------------------------------------
| Direct Text Integration Boundary
|--------------------------------------------------------------------------
| A real Sanctum personal access token must carry the exact text-ingestion
| ability. A separate dataset grant constrains which dataset the principal may
| write. Session-only authentication and wildcard token abilities are rejected.
*/
Route::post('/integrations/text-ingestions', TextIngestionController::class)
    ->middleware([
        'auth:sanctum',
        RequireTextIngestionToken::class,
        LimitTextIngestionRequestSize::class,
    ]);
Route::delete('/integrations/text-ingestions/{sourceId}', TextIngestionDeletionController::class)
    ->where('sourceId', 'source_[0-9a-f]{32}')
    ->middleware([
        'auth:sanctum',
        RequireTextIngestionToken::class,
    ]);

/*
|--------------------------------------------------------------------------
| Management API Boundary
|--------------------------------------------------------------------------
| Single-user deployments expose the management API directly.
*/
/*
|----------------------------------------------------------------------
| Platform Connectivity
|----------------------------------------------------------------------
| Unlike the minimal /up liveness check, ping confirms that the caller can
| reach the application API boundary.
*/
Route::get('/ping', static fn (): JsonResponse => response()->json(['pong' => true]));

/*
|----------------------------------------------------------------------
| Runtime Settings Domain
|----------------------------------------------------------------------
| Reads and updates the runtime-managed converter and model defaults.
| These routes currently belong to the Svelte settings UI rather than the
| published external API contract.
*/
Route::get('/settings/config', [SettingsController::class, 'show'])
    ->defaults('openapi', false);
Route::put('/settings/config', [SettingsController::class, 'update'])
    ->defaults('openapi', false);

/*
|----------------------------------------------------------------------
| Dataset Domain
|----------------------------------------------------------------------
| Dataset routes manage searchable dataset metadata. Storage cleanup is
| separated from metadata reads and creation because it deletes Qdrant and
| Neo4j data.
| Self-granting query access requires a query principal and applies only to
| that current user and the selected dataset. Self-granting ingest access
| requires a personal access token with the rag:text-ingest ability — the
| same credential boundary as the text-ingestion endpoints themselves.
*/
Route::prefix('datasets')->group(function (): void {
    Route::get('/', [DatasetController::class, 'index']);
    Route::post('/', [DatasetController::class, 'store'])
        ->middleware(['auth:sanctum', RequireDatasetManager::class]);
    Route::get('/{datasetId}', [DatasetController::class, 'show']);
    Route::post('/{datasetId}/query-grants/self', [DatasetQueryGrantController::class, 'store'])
        ->middleware('browser-query-principal')
        ->defaults('openapi', false);
    Route::post('/{datasetId}/ingest-grants/self', [DatasetIngestionGrantController::class, 'store'])
        ->middleware(['auth:sanctum', RequireTextIngestionToken::class]);
    Route::delete('/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])
        ->middleware(['auth:sanctum', RequireDatasetManager::class]);
});

/*
|----------------------------------------------------------------------
| Document Domain
|----------------------------------------------------------------------
| Provides document browsing plus the unified create, batch, update, and
| delete lifecycle. The source download route serves only validated uploads.
*/
Route::prefix('documents')->group(function (): void {
    Route::get('/', [DocumentBrowserController::class, 'index']);
    Route::post('/', [UnifiedDocumentController::class, 'store'])->middleware(RequireManagedMutationPrincipal::class);
    Route::post('/batch', [UnifiedDocumentController::class, 'storeBatch'])->middleware(RequireManagedMutationPrincipal::class);
    Route::get('/uploads/download', UploadedSourceDocumentController::class)
        ->name('documents.uploads.download')
        ->defaults('openapi', false);
    Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
    Route::put('/{documentId}', [UnifiedDocumentController::class, 'update'])->middleware(RequireManagedMutationPrincipal::class);
    Route::delete('/{documentId}', [UnifiedDocumentController::class, 'destroy'])->middleware(RequireManagedMutationPrincipal::class);
});

/*
|----------------------------------------------------------------------
| Scraper Domain
|----------------------------------------------------------------------
| Provides the read-only crawler catalog used by the
| pipeline UI. These integration-specific contracts remain hidden from
| OpenAPI until the external crawler protocol is treated as stable.
*/
Route::prefix('scraper')->group(function (): void {
    Route::get('/tasks', [ScrapeController::class, 'getCrawlerTasks'])
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
        ->middleware(CrawlerIngestionBoundary::class)->defaults('openapi', false);

    // Task routes expose the aggregate state, stage evidence, and controls
    // for a complete scrape -> convert -> ingest execution.
    Route::prefix('tasks')->middleware(CrawlerIngestionBoundary::class)->group(function (): void {
        Route::get('/', [PipelineTaskController::class, 'index']);
        Route::post('/start', [PipelineTaskController::class, 'start']);
        Route::get('/{taskId}', [PipelineTaskController::class, 'show']);
        Route::get('/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
        Route::get('/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
        Route::get('/{taskId}/events', [PipelineTaskController::class, 'events']);
        Route::get('/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
        Route::get('/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
        Route::post('/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
        Route::post('/{taskId}/retry', [PipelineTaskController::class, 'retry'])
            ->defaults('openapi', false);
        Route::post('/{taskId}/cancel', [PipelineTaskController::class, 'cancel']);
        Route::delete('/{taskId}', [PipelineTaskController::class, 'destroy']);
    });

    // File ingestion stores the upload and starts a workflow.
    Route::post('/controller/files', [PipelineControlController::class, 'uploadFile'])->middleware(RequireManagedMutationPrincipal::class);

    // Recovery mutations requeue failed jobs and tasks.
    Route::prefix('recovery')->middleware(CrawlerIngestionBoundary::class)->group(function (): void {
        Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
        Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected']);
        Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob']);
        Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll']);
        Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask']);
        Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset']);
    });

    // Pipeline health provides detailed diagnostics; infrastructure probes
    // should continue to use the minimal /up liveness endpoint.
    Route::get('/health', [PipelineHealthController::class, 'show']);

    Route::delete('/sources/{sourceId}', [PipelineTaskController::class, 'urlSourceBoundary'])
        ->middleware(CrawlerIngestionBoundary::class)->defaults('openapi', false);
    Route::post('/sources/{sourceId}/recrawl', [PipelineTaskController::class, 'urlSourceBoundary'])
        ->middleware(CrawlerIngestionBoundary::class)->defaults('openapi', false);
});

/*
|----------------------------------------------------------------------
| Health and Monitoring Domain
|----------------------------------------------------------------------
| Reports RAG bridge health, runtime monitoring, and the combined system
| gate. /up provides the minimal liveness endpoint for infrastructure probes.
*/
Route::get('/rag/health', [RagHealthController::class, 'show']);
Route::get('/rag/monitor', [RagMonitorController::class, 'show'])->middleware(['auth:sanctum', RequireDatasetManager::class]);
Route::get('/health/system-gate', [HawkiRagSystemGateController::class, 'show'])->middleware(['auth:sanctum', RequireDatasetManager::class]);

/*
|----------------------------------------------------------------------
| Vector Storage Domain
|----------------------------------------------------------------------
| Reports RAG/Qdrant statistics. Storage deletion uses the dataset API.
*/
Route::get('/rag/stats', [RagStatsController::class, 'show'])->middleware(['auth:sanctum', RequireDatasetManager::class]);

/*
|----------------------------------------------------------------------
| Knowledge Graph Domain
|----------------------------------------------------------------------
| Supports graph exploration, semantic search, expansion, and saved views.
| Clearing the persisted Neo4j graph is distinct from clearing a browser
| view.
*/
Route::prefix('rag/neo4j')->group(function (): void {
    foreach (['overview', 'search', 'semantic-search', 'node'] as $operation) {
        Route::get('/graph/'.$operation, [GraphOpsController::class, $operation === 'semantic-search' ? 'semanticSearch' : $operation])
            ->middleware('browser-query-principal');
    }
    Route::post('/graph/expand', [GraphOpsController::class, 'expand'])
        ->middleware('browser-query-principal');
    Route::post('/graph/clear-view', ClearGraphViewController::class);
    Route::get('/graph/snapshots', [GraphSnapshotController::class, 'index'])->middleware('browser-query-principal');
    Route::post('/graph/snapshots', [GraphSnapshotController::class, 'store'])->middleware('browser-query-principal');
    Route::get('/graph/snapshots/{id}', [GraphSnapshotController::class, 'show'])->middleware('browser-query-principal');
    Route::delete('/graph/snapshots/{id}', [GraphSnapshotController::class, 'destroy'])->middleware('browser-query-principal');
    Route::post('/clear', ClearNeo4jController::class)->middleware(['auth:sanctum', RequireDatasetManager::class]);
});
