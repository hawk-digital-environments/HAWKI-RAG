<?php

use App\Http\Controllers\AdminPanelController;
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\IngestController;
use App\Http\Controllers\API\IngestStatusController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\ScrapeController;

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hawki-rag-playground');

// HAWKI RAG playground helpers

Route::get('/hawki-rag-playground', function () {
    return view('hawki-rag-playground', [
        'chatPrompt' => config('model_prompts.prompts.chat') ?? '',
        'ragPrompt'  => config('model_prompts.prompts.rag') ?? '',
    ]);
});

Route::post('/requestScrape', [ScrapeController::class, 'requestScrape']);
Route::post('/cancelScrape', [ScrapeController::class, 'cancelScrape']);
Route::post('/getAllScrapes', [ScrapeController::class, 'getAllScrapes']);
Route::post('/deleteScrapeJob', [ScrapeController::class, 'deleteScrapeJob']);
Route::post('/deleteScrapeContent', [ScrapeController::class, 'deleteScrapeContent']);
Route::post('/getScrapeInformation', [ScrapeController::class, 'getScrapeInformation']);
Route::post('/getScrapeResult', [ScrapeController::class, 'getScrapeResult']);
Route::post('/extractPageContent', [ScrapeController::class, 'extractPageContent']);
Route::get('/scraper/jobs', [ScrapeController::class, 'getCrawlerJobs']);
Route::get('/scraper/status/{jobId}', [ScrapeController::class, 'getCrawlerStatus']);
Route::post('/scraper/jobs/{jobId}/cancel', [ScrapeController::class, 'cancelCrawlerJob']);
Route::post('/scraper/jobs/{jobId}/pause', [ScrapeController::class, 'pauseCrawlerJob']);
Route::post('/scraper/jobs/{jobId}/resume', [ScrapeController::class, 'resumeCrawlerJob']);
Route::get('/pipeline/status/{jobId}', [PipelineStatusController::class, 'show']);
// Playground related routes

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
