<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\IngestStatusController;
use App\Http\Controllers\API\IngestController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\PipelineProfileController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineTaskController;

// Health check
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
});

Route::prefix('pipeline/profiles')->group(function () {
    Route::get('/', [PipelineProfileController::class, 'index']);
    Route::post('/', [PipelineProfileController::class, 'store']);
    Route::get('/{profileId}', [PipelineProfileController::class, 'show']);
    Route::put('/{profileId}', [PipelineProfileController::class, 'update']);
    Route::patch('/{profileId}', [PipelineProfileController::class, 'update']);
    Route::post('/{profileId}/start-task', [PipelineProfileController::class, 'startTask']);
});

Route::prefix('pipeline/recovery')->group(function () {
    Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
    Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected']);
    Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob']);
    Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll']);
    Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask']);
    Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/query', [HawkiRagProxyController::class, 'query']);
    Route::get('/ingest/status', [IngestStatusController::class, 'show']);
    Route::post('/ingest/status/clear', [IngestStatusController::class, 'clear']);
    Route::get('/ingest/folders', [IngestController::class, 'folders']);
    Route::get('/ingest/live', [IngestController::class, 'live']);
    Route::post('/ingest/start', [IngestController::class, 'start']);
    Route::post('/ingest/stop', [IngestController::class, 'stop']);
    Route::post('/ingest/delete', [IngestController::class, 'deleteFolder']);
    Route::get('/rag/health', [RagHealthController::class, 'show']);
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
