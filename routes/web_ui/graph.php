<?php

use App\Http\Controllers\Graph\RagGraphController;
use App\Services\Profile\OperatorAccessService;
use Illuminate\Support\Facades\Route;

Route::get('/neo4j-graph-explorer', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'Neo4j Graph Explorer',
        'vite' => ['resources/css/app.css', 'resources/js/neo4j-graph-dashboard.js'],
        'configScriptId' => 'neo4j-graph-dashboard-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-neo4j-graph-dashboard' => true],
    ]);
});

Route::middleware('operator')->group(function (): void {
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
    Route::post('/rag/neo4j/clear', [RagGraphController::class, 'clearNeo4j'])->middleware('throttle:hawki-destructive');
});
