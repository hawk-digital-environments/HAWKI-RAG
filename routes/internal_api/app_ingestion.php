<?php

use App\Http\Controllers\PipelineControlController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::post('/pipeline/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
});
