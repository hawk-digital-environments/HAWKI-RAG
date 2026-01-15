<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RawkiProxyController;
use App\Http\Controllers\API\McpMonitorController;
use App\Http\Controllers\API\IngestStatusController;
use App\Http\Controllers\API\IngestController;
use App\Http\Controllers\API\RagHealthController;
use App\Http\Controllers\API\RagStatsController;

// Health check
Route::get('/ping', fn() => response()->json(['pong' => true]));
Route::post('/rawki/query', [RawkiProxyController::class, 'query']);
Route::get('/mcp/monitor', [McpMonitorController::class, 'latest']);
Route::post('/mcp/monitor/clear', [McpMonitorController::class, 'clear']);
Route::get('/ingest/status', [IngestStatusController::class, 'show']);
Route::post('/ingest/status/clear', [IngestStatusController::class, 'clear']);
Route::get('/ingest/folders', [IngestController::class, 'folders']);
Route::post('/ingest/start', [IngestController::class, 'start']);
Route::post('/ingest/stop', [IngestController::class, 'stop']);
Route::get('/rag/health', [RagHealthController::class, 'show']);
Route::get('/rag/stats', [RagStatsController::class, 'show']);
