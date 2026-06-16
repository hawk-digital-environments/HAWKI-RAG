<?php

use App\Http\Controllers\AdminPanelController;
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagMonitorController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineHealthController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hawki-rag-playground');
Route::get('/swagger', fn () => redirect('/swagger/index.html'));

// HAWKI RAG playground helpers

Route::get('/hawki-rag-playground', function () {
    return view('hawki-rag-playground', [
        'chatPrompt' => config('model_prompts.prompts.chat') ?? '',
        'ragPrompt'  => config('model_prompts.prompts.rag') ?? '',
    ]);
});

Route::get('/neo4j-graph-explorer', function () {
    return view('neo4j-graph-dashboard');
});

Route::get('/pipeline-controller', function () {
    return view('pipeline-controller-dashboard');
});

Route::get('/pipeline-health', function () {
    return view('pipeline-health-dashboard');
});

Route::get('/datasets', function () {
    return view('datasets-dashboard');
});
Route::get('/datasets/data', [DatasetController::class, 'index']);
Route::get('/datasets/data/{datasetId}', [DatasetController::class, 'show']);
Route::delete('/datasets/data/{datasetId}/storage', [DatasetController::class, 'destroyStorage']);

Route::get('/documents', function (\Illuminate\Http\Request $request) {
    $query = $request->getQueryString();

    return redirect('/datasets'.($query ? '?'.$query : ''));
});
Route::get('/documents/data', [DocumentBrowserController::class, 'index']);
Route::get('/documents/data/{documentId}', [DocumentBrowserController::class, 'show']);

Route::post('/requestScrape', [ScrapeController::class, 'requestScrape']);
Route::post('/cancelScrape', [ScrapeController::class, 'cancelScrape']);
Route::post('/getAllScrapes', [ScrapeController::class, 'getAllScrapes']);
Route::post('/deleteScrapeJob', [ScrapeController::class, 'deleteScrapeJob']);
Route::post('/deleteScrapeContent', [ScrapeController::class, 'deleteScrapeContent']);
Route::post('/getScrapeInformation', [ScrapeController::class, 'getScrapeInformation']);
Route::post('/getScrapeResult', [ScrapeController::class, 'getScrapeResult']);
Route::post('/extractPageContent', [ScrapeController::class, 'extractPageContent']);
Route::get('/scraper/jobs', [ScrapeController::class, 'getCrawlerJobs']);
Route::get('/scraper/tasks', [ScrapeController::class, 'getCrawlerTasks']);
Route::post('/scraper/tasks/start', [ScrapeController::class, 'startCrawlerTask']);
Route::get('/scraper/status/{jobId}', [ScrapeController::class, 'getCrawlerStatus']);
Route::post('/scraper/jobs/{jobId}/cancel', [ScrapeController::class, 'cancelCrawlerJob']);
Route::post('/scraper/jobs/{jobId}/pause', [ScrapeController::class, 'pauseCrawlerJob']);
Route::post('/scraper/jobs/{jobId}/resume', [ScrapeController::class, 'resumeCrawlerJob']);
Route::get('/pipeline/status/{jobId}', [PipelineStatusController::class, 'show']);
Route::get('/pipeline/tasks', [PipelineTaskController::class, 'index']);
Route::post('/pipeline/tasks/start', [PipelineTaskController::class, 'start']);
Route::get('/pipeline/tasks/{taskId}', [PipelineTaskController::class, 'show']);
Route::get('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
Route::get('/pipeline/tasks/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
Route::get('/pipeline/tasks/{taskId}/events', [PipelineTaskController::class, 'events']);
Route::post('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
Route::post('/pipeline/tasks/{taskId}/retry', [PipelineTaskController::class, 'retry']);
Route::post('/pipeline/tasks/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs']);
Route::post('/pipeline/tasks/{taskId}/cancel', [PipelineTaskController::class, 'cancel']);
Route::get('/pipeline/health', [PipelineHealthController::class, 'show']);
Route::post('/pipeline/controller/files', [PipelineControlController::class, 'uploadFile']);
Route::get('/pipeline/recovery/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
Route::post('/pipeline/recovery/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected']);
Route::post('/pipeline/recovery/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob']);
Route::post('/pipeline/recovery/retry-all', [PipelineRecoveryController::class, 'retryAll']);
Route::post('/pipeline/recovery/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask']);
Route::post('/pipeline/recovery/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset']);
// Playground related routes

Route::post('/query', [HawkiRagProxyController::class, 'query']);
Route::get('/rag/health', [RagHealthController::class, 'show']);
Route::get('/rag/monitor', [RagMonitorController::class, 'show']);
Route::get('/rag/stats', [RagStatsController::class, 'show']);
Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection']);
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
