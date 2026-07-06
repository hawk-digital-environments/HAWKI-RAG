<?php

use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\UploadedSourceDocumentController;
use App\Services\Profile\OperatorAccessService;
use Illuminate\Support\Facades\Route;

$heapBrowserPage = function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'HAWKI Heap Browser',
        'vite' => ['resources/css/datasets-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/css/hawki-rag-theme.css', 'resources/js/datasets-dashboard.js'],
        'configScriptId' => 'datasets-dashboard-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-datasets-dashboard' => true],
    ]);
};

Route::get('/heaps', $heapBrowserPage);
Route::get('/datasets', $heapBrowserPage);

Route::middleware('operator')->group(function (): void {
    Route::get('/heaps/data', [DatasetController::class, 'index']);
    Route::get('/heaps/data/{datasetId}', [DatasetController::class, 'show']);
    Route::delete('/heaps/data/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])->middleware('throttle:hawki-destructive');
    Route::get('/datasets/data', [DatasetController::class, 'index']);
    Route::get('/datasets/data/{datasetId}', [DatasetController::class, 'show']);
    Route::delete('/datasets/data/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])->middleware('throttle:hawki-destructive');
});

Route::get('/documents', function (\Illuminate\Http\Request $request) {
    $query = $request->getQueryString();

    return redirect('/heaps'.($query ? '?'.$query : ''));
});

Route::middleware('operator')->group(function (): void {
    Route::get('/documents/data', [DocumentBrowserController::class, 'index']);
    Route::get('/documents/data/{documentId}', [DocumentBrowserController::class, 'show']);
    Route::get('/documents/uploads/download', UploadedSourceDocumentController::class)
        ->name('documents.uploads.download');
});
