<?php

/*
|--------------------------------------------------------------------------
| Internal API Routes
|--------------------------------------------------------------------------
| System-to-system and token-authenticated API endpoints live here.
| Laravel mounts this file under /api from bootstrap/app.php.
*/

/*
|--------------------------------------------------------------------------
| Laravel Route Helper
|--------------------------------------------------------------------------
| Route: registers endpoint paths, HTTP verbs, prefixes, and middleware.
*/
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RAG Bridge and Vector Operations
|--------------------------------------------------------------------------
| HawkiRagProxyController: forwards query requests to the RAG bridge.
| RagStatsController: exposes vector/Qdrant stats and collection cleanup.
*/
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Assistant\AssistantDocumentController;
use App\Http\Controllers\Document\UnifiedDocumentController;

/*
|--------------------------------------------------------------------------
| Graph API
|--------------------------------------------------------------------------
| RagGraphController: searches, expands, snapshots, and clears Neo4j graph data.
*/
use App\Http\Controllers\Graph\RagGraphController;

/*
|--------------------------------------------------------------------------
| Dataset and Document API
|--------------------------------------------------------------------------
| DatasetController: lists, creates, shows, and cleans dataset storage.
| DocumentBrowserController: lists and shows documents inside datasets.
*/
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;

/*
|--------------------------------------------------------------------------
| Pipeline API
|--------------------------------------------------------------------------
| PipelineControlController: receives file uploads for ingestion.
| PipelineRecoveryController: retries failed jobs, tasks, and datasets.
| PipelineTaskController: manages task status, jobs, events, retries, cancel,
| and cache/history deletion.
*/
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineTaskController;

Route::middleware(['auth:sanctum', 'throttle:hawki-api'])->group(function () {
    Route::prefix('datasets')->group(function () {
        Route::get('/', [DatasetController::class, 'index']);
        Route::post('/', [DatasetController::class, 'store']);
        Route::get('/{datasetId}', [DatasetController::class, 'show']);
        Route::delete('/{datasetId}/storage', [DatasetController::class, 'destroyStorage']);
    });

    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentBrowserController::class, 'index']);
        Route::post('/', [UnifiedDocumentController::class, 'store'])->middleware('throttle:hawki-upload');
        Route::post('/batch', [UnifiedDocumentController::class, 'storeBatch'])->middleware('throttle:hawki-upload');
        Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
        Route::put('/{documentId}', [UnifiedDocumentController::class, 'update'])->middleware('throttle:hawki-upload');
        Route::delete('/{documentId}', [UnifiedDocumentController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('assistant/documents')->group(function () {
        Route::post('/', [AssistantDocumentController::class, 'store'])->middleware('throttle:hawki-upload');
        Route::post('/batch', [AssistantDocumentController::class, 'storeBatch'])->middleware('throttle:hawki-upload');
        Route::get('/{assistantDocumentId}', [AssistantDocumentController::class, 'show']);
        Route::put('/{assistantDocumentId}', [AssistantDocumentController::class, 'update'])->middleware('throttle:hawki-upload');
        Route::delete('/{assistantDocumentId}', [AssistantDocumentController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('pipeline/tasks')->group(function () {
        Route::get('/', [PipelineTaskController::class, 'index']);
        Route::post('/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
        Route::get('/{taskId}', [PipelineTaskController::class, 'show']);
        Route::get('/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
        Route::get('/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
        Route::get('/{taskId}/events', [PipelineTaskController::class, 'events']);
        Route::get('/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
        Route::get('/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
        Route::post('/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
        Route::post('/{taskId}/retry', [PipelineTaskController::class, 'retry'])->middleware('throttle:hawki-destructive');
        Route::post('/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs'])->middleware('throttle:hawki-destructive');
        Route::post('/{taskId}/cancel', [PipelineTaskController::class, 'cancel'])->middleware('throttle:hawki-destructive');
        Route::delete('/{taskId}', [PipelineTaskController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('pipeline/controller')->group(function () {
        Route::post('/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
    });

    Route::prefix('pipeline/recovery')->group(function () {
        Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
        Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])->middleware('throttle:hawki-destructive');
        Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])->middleware('throttle:hawki-destructive');
        Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll'])->middleware('throttle:hawki-destructive');
        Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])->middleware('throttle:hawki-destructive');
        Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
    });

    Route::post('/query', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
    Route::get('/rag/stats', [RagStatsController::class, 'show']);
    Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])->middleware('throttle:hawki-destructive');
    Route::get('/rag/neo4j/graph/overview', [RagGraphController::class, 'overview']);
    Route::get('/rag/neo4j/graph/search', [RagGraphController::class, 'search']);
    Route::get('/rag/neo4j/graph/semantic-search', [RagGraphController::class, 'semanticSearch']);
    Route::get('/rag/neo4j/graph/node', [RagGraphController::class, 'node']);
    Route::post('/rag/neo4j/graph/expand', [RagGraphController::class, 'expand']);
    Route::post('/rag/neo4j/graph/clear-view', [RagGraphController::class, 'clearView']);
    Route::get('/rag/neo4j/graph/snapshots', [RagGraphController::class, 'snapshots']);
    Route::post('/rag/neo4j/graph/snapshots', [RagGraphController::class, 'saveSnapshot']);
    Route::get('/rag/neo4j/graph/snapshots/{id}', [RagGraphController::class, 'loadSnapshot']);
    Route::delete('/rag/neo4j/graph/snapshots/{id}', [RagGraphController::class, 'deleteSnapshot']);
    Route::post('/rag/neo4j/clear', [RagGraphController::class, 'clearNeo4j'])->middleware('throttle:hawki-destructive');
});
