<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagMonitorController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\PipelineHealthController;
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineTaskController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ping', fn() => response()->json(['pong' => true]));

    Route::prefix('datasets')->group(function () {
        Route::get('/', [DatasetController::class, 'index']);
        Route::post('/', [DatasetController::class, 'store']);
        Route::get('/{datasetId}', [DatasetController::class, 'show']);
    });

    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentBrowserController::class, 'index']);
        Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
    });

    Route::prefix('pipeline/tasks')->group(function () {
        Route::get('/', [PipelineTaskController::class, 'index']);
        Route::post('/start', [PipelineTaskController::class, 'start']);
        Route::get('/{taskId}', [PipelineTaskController::class, 'show']);
        Route::get('/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
        Route::get('/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
        Route::get('/{taskId}/events', [PipelineTaskController::class, 'events']);
        Route::post('/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
        Route::post('/{taskId}/retry', [PipelineTaskController::class, 'retry']);
        Route::post('/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs']);
        Route::post('/{taskId}/cancel', [PipelineTaskController::class, 'cancel']);
    });

    Route::prefix('pipeline/health')->group(function () {
        Route::get('/', [PipelineHealthController::class, 'show']);
    });

    Route::prefix('pipeline/controller')->group(function () {
        Route::post('/files', [PipelineControlController::class, 'uploadFile']);
    });

    Route::prefix('pipeline/recovery')->group(function () {
        Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
        Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected']);
        Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob']);
        Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll']);
        Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask']);
        Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset']);
    });

    Route::post('/query', [HawkiRagProxyController::class, 'query']);
    Route::get('/rag/health', [RagHealthController::class, 'show']);
    Route::get('/rag/monitor', [RagMonitorController::class, 'show']);
    Route::get('/rag/stats', [RagStatsController::class, 'show']);
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
    Route::post('/rag/neo4j/clear', [RagGraphController::class, 'clearNeo4j']);
});
