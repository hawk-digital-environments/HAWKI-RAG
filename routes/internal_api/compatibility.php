<?php

use App\Http\Controllers\API\OpenCompat\DocumentController as OpenCompatDocumentController;
use App\Http\Controllers\API\OpenCompat\FolderController as OpenCompatFolderController;
use App\Http\Controllers\API\OpenCompat\IngestController as OpenCompatIngestController;
use App\Http\Controllers\API\OpenCompat\ModelController as OpenCompatModelController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('ingest')->group(function () {
        Route::post('/text', [OpenCompatIngestController::class, 'text'])->middleware('throttle:hawki-rag-query');
        Route::post('/file', [OpenCompatIngestController::class, 'file'])->middleware('throttle:hawki-upload');
        Route::post('/files', [OpenCompatIngestController::class, 'files'])->middleware('throttle:hawki-upload');
        Route::post('/requeue', [OpenCompatIngestController::class, 'requeue'])->middleware('throttle:hawki-destructive');
        Route::post('/document/query', [OpenCompatIngestController::class, 'documentQuery'])->middleware('throttle:hawki-upload');
    });

    Route::prefix('datasets')->group(function () {
        Route::get('/', [DatasetController::class, 'index']);
        Route::post('/', [DatasetController::class, 'store']);
        Route::get('/{datasetId}', [DatasetController::class, 'show']);
        Route::delete('/{datasetId}/storage', [DatasetController::class, 'destroyStorage']);
    });

    Route::prefix('documents')->group(function () {
        Route::post('/', [OpenCompatDocumentController::class, 'list']);
        Route::post('/list_docs', [OpenCompatDocumentController::class, 'list']);
        Route::post('/pages', [OpenCompatDocumentController::class, 'pages']);
        Route::get('/filename/{filename}', [OpenCompatDocumentController::class, 'byFilename'])->where('filename', '.*');
        Route::get('/', [DocumentBrowserController::class, 'index']);
        Route::get('/{documentId}/status', [OpenCompatDocumentController::class, 'status']);
        Route::get('/{documentId}/summary', [OpenCompatDocumentController::class, 'summary']);
        Route::put('/{documentId}/summary', [OpenCompatDocumentController::class, 'summary']);
        Route::get('/{documentId}/download_url', [OpenCompatDocumentController::class, 'downloadUrl']);
        Route::get('/{documentId}/file', [OpenCompatDocumentController::class, 'file']);
        Route::post('/{documentId}/update_text', [OpenCompatDocumentController::class, 'updateText'])->middleware('throttle:hawki-upload');
        Route::post('/{documentId}/update_file', [OpenCompatDocumentController::class, 'updateFile'])->middleware('throttle:hawki-upload');
        Route::post('/{documentId}/update_metadata', [OpenCompatDocumentController::class, 'updateMetadata']);
        Route::delete('/{documentId}', [OpenCompatDocumentController::class, 'delete'])->middleware('throttle:hawki-destructive');
        Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
    });

    Route::prefix('folders')->group(function () {
        Route::post('/', [OpenCompatFolderController::class, 'create']);
        Route::get('/', [OpenCompatFolderController::class, 'list']);
        Route::post('/details', [OpenCompatFolderController::class, 'details']);
        Route::get('/summary', [OpenCompatFolderController::class, 'listSummaries']);
        Route::get('/{folderId}/summary', [OpenCompatFolderController::class, 'summary'])->where('folderId', '.*');
        Route::put('/{folderId}/summary', [OpenCompatFolderController::class, 'summary'])->where('folderId', '.*');
        Route::post('/{folderId}/documents/{documentId}', [OpenCompatFolderController::class, 'attachDocument'])->where('folderId', '.*');
        Route::delete('/{folderId}/documents/{documentId}', [OpenCompatFolderController::class, 'detachDocument'])->where('folderId', '.*');
        Route::post('/{folderId}/move', [OpenCompatFolderController::class, 'move'])->where('folderId', '.*');
        Route::delete('/{folderId}', [OpenCompatFolderController::class, 'delete'])->where('folderId', '.*')->middleware('throttle:hawki-destructive');
        Route::get('/{folderId}', [OpenCompatFolderController::class, 'show'])->where('folderId', '.*');
    });

    Route::get('/models', [OpenCompatModelController::class, 'list']);
    Route::get('/models/available', [OpenCompatModelController::class, 'list']);
    Route::post('/models', [OpenCompatModelController::class, 'unsupported']);
    Route::get('/models/custom', [OpenCompatModelController::class, 'unsupported']);
    Route::delete('/models/{modelId}', [OpenCompatModelController::class, 'unsupported'])->middleware('throttle:hawki-destructive');
});
