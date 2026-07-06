<?php

use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\ScrapeTaskUiProxyController;
use App\Services\Profile\OperatorAccessService;
use Illuminate\Support\Facades\Route;

Route::get('/pipeline-controller', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) use ($pipelineControllerConfig) {
    return view('svelte-page', [
        'title' => 'HAWKI Pipeline Controller',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/pipeline-controller.js'],
        'configScriptId' => 'pipeline-controller-config',
        'config' => [
            ...$pipelineControllerConfig(),
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-pipeline-controller-dashboard' => true],
    ]);
});

Route::middleware('operator')->group(function (): void {
    Route::get('/scraper/jobs', [ScrapeController::class, 'getCrawlerJobs']);
    Route::get('/scraper/tasks', [ScrapeController::class, 'getCrawlerTasks']);
    Route::post('/scraper/tasks/start', [ScrapeController::class, 'startCrawlerTask'])->middleware('throttle:hawki-upload');
    Route::get('/scraper/status/{jobId}', [ScrapeController::class, 'getCrawlerStatus']);
    Route::post('/scraper/jobs/{jobId}/cancel', [ScrapeController::class, 'cancelCrawlerJob']);
    Route::post('/scraper/jobs/{jobId}/pause', [ScrapeController::class, 'pauseCrawlerJob']);
    Route::post('/scraper/jobs/{jobId}/resume', [ScrapeController::class, 'resumeCrawlerJob']);
    Route::get('/pipeline/status/{jobId}', [PipelineStatusController::class, 'show']);
    Route::get('/pipeline/tasks', [PipelineTaskController::class, 'index']);
    Route::post('/pipeline/tasks/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
    Route::get('/pipeline/tasks/{taskId}', [PipelineTaskController::class, 'show']);
    Route::get('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
    Route::get('/pipeline/tasks/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
    Route::get('/pipeline/tasks/{taskId}/events', [PipelineTaskController::class, 'events']);
    Route::get('/pipeline/tasks/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
    Route::get('/pipeline/tasks/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
    Route::post('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
    Route::post('/pipeline/tasks/{taskId}/retry', [PipelineTaskController::class, 'retry'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/tasks/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/tasks/{taskId}/cancel', [PipelineTaskController::class, 'cancel'])->middleware('throttle:hawki-destructive');
    Route::delete('/pipeline/tasks/{taskId}', [PipelineTaskController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/controller/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
});

Route::middleware('operator')->group(function (): void {
    Route::get('/pipeline/recovery/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
    Route::post('/pipeline/recovery/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/retry-all', [PipelineRecoveryController::class, 'retryAll'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/heaps/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
});

Route::any('/ui/{path?}', ScrapeTaskUiProxyController::class)
    ->where('path', '.*');
