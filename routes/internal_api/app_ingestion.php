<?php

use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('pipeline/tasks')->group(function () {
        Route::post('/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
    });

    Route::post('/pipeline/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
});
