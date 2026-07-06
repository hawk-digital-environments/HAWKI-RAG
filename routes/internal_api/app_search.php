<?php

use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\OpenCompat\RetrievalController as OpenCompatRetrievalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('search')->group(function () {
        Route::post('/', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks', [OpenCompatRetrievalController::class, 'chunks'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks/grouped', [OpenCompatRetrievalController::class, 'groupedChunks'])->middleware('throttle:hawki-rag-query');
    });
});
