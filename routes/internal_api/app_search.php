<?php

use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\SearchChunkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('search')->group(function () {
        Route::post('/', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks', [SearchChunkController::class, 'chunks'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks/grouped', [SearchChunkController::class, 'groupedChunks'])->middleware('throttle:hawki-rag-query');
    });
});
