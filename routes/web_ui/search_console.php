<?php

use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagStatsController;
use App\Services\Profile\OperatorAccessService;
use Illuminate\Support\Facades\Route;

$searchConsolePage = function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'HAWKI RAG Search Console',
        'vite' => 'resources/js/hawki-rag-playground.js',
        'configScriptId' => 'hawki-rag-playground-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-hawki-rag-playground' => true],
    ]);
};

Route::get('/hawki-rag-search', $searchConsolePage);
Route::get('/hawki-rag-playground', $searchConsolePage);

Route::middleware('operator')->group(function (): void {
    Route::post('/search', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
    Route::post('/query', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
    Route::get('/rag/stats', [RagStatsController::class, 'show']);
    Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])->middleware('throttle:hawki-destructive');
});
