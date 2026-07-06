<?php

use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('pipeline/tasks')->group(function () {
        Route::post('/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
    });

    Route::prefix('pipeline/controller')->group(function () {
        Route::post('/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
    });
});
