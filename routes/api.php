<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\IngestStatusController;
use App\Http\Controllers\API\IngestController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\Graph\RagGraphController;
use App\Http\Controllers\API\PipelineController;

// Health check
Route::get('/ping', fn() => response()->json(['pong' => true]));
Route::post('/hawki-rag/query', [HawkiRagProxyController::class, 'query']);
Route::get('/ingest/status', [IngestStatusController::class, 'show']);
Route::post('/ingest/status/clear', [IngestStatusController::class, 'clear']);
Route::get('/ingest/folders', [IngestController::class, 'folders']);
Route::get('/ingest/live', [IngestController::class, 'live']);
Route::post('/ingest/start', [IngestController::class, 'start']);
Route::post('/ingest/stop', [IngestController::class, 'stop']);
Route::post('/ingest/delete', [IngestController::class, 'deleteFolder']);
Route::post('/pipeline/start', [PipelineController::class, 'start']);
Route::get('/pipeline/status', [PipelineController::class, 'status']);
Route::get('/rag/health', [RagHealthController::class, 'show']);
Route::get('/rag/stats', [RagStatsController::class, 'show']);
Route::post('/rag/neo4j/clear', [RagGraphController::class, 'clearNeo4j']);
