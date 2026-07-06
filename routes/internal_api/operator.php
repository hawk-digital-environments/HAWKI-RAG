<?php

use App\Http\Controllers\API\OpenCompat\ApiKeyController as OpenCompatApiKeyController;
use App\Http\Controllers\API\OpenCompat\SystemController as OpenCompatSystemController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum,oidc', 'throttle:hawki-api'])->group(function () {
    Route::get('/api-keys', [OpenCompatApiKeyController::class, 'list']);
    Route::post('/api-keys', [OpenCompatApiKeyController::class, 'save']);
    Route::post('/migrate/document', [OpenCompatSystemController::class, 'migrateDocument'])->middleware('throttle:hawki-upload');
    Route::get('/logs', [OpenCompatSystemController::class, 'logs']);
    Route::get('/usage/app-storage', [OpenCompatSystemController::class, 'usage']);

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
        Route::post('/heaps/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
        Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
    });

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
