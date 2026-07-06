<?php

use App\Http\Controllers\SpecV2\ApplicationController as SpecApplicationController;
use App\Http\Controllers\SpecV2\AuthorizationController as SpecAuthorizationController;
use App\Http\Controllers\SpecV2\CorpusController as SpecCorpusController;
use App\Http\Controllers\SpecV2\DocumentController as SpecDocumentController;
use App\Http\Controllers\SpecV2\GroupController as SpecGroupController;
use App\Http\Controllers\SpecV2\HeapController as SpecHeapController;
use App\Http\Controllers\SpecV2\TenantController as SpecTenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:application-token', 'throttle:hawki-api'])->group(function () {
    Route::prefix('tenants')->group(function () {
        Route::get('/', [SpecTenantController::class, 'index']);
        Route::post('/', [SpecTenantController::class, 'store']);
        Route::get('/{tenantId}', [SpecTenantController::class, 'show']);
    });

    Route::prefix('applications')->group(function () {
        Route::get('/', [SpecApplicationController::class, 'index']);
        Route::post('/', [SpecApplicationController::class, 'store']);
        Route::get('/{applicationId}', [SpecApplicationController::class, 'show']);
    });

    Route::prefix('heaps')->group(function () {
        Route::get('/', [SpecHeapController::class, 'index']);
        Route::post('/', [SpecHeapController::class, 'store']);
        Route::get('/{heapId}', [SpecHeapController::class, 'show']);
        Route::patch('/{heapId}', [SpecHeapController::class, 'update']);
        Route::get('/{heapId}/documents', [SpecDocumentController::class, 'index']);
        Route::post('/{heapId}/documents', [SpecDocumentController::class, 'store'])->middleware('throttle:hawki-upload');
        Route::delete('/{heapId}', [SpecHeapController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('documents')->group(function () {
        Route::put('/{documentId}', [SpecDocumentController::class, 'update'])->middleware('throttle:hawki-upload');
        Route::delete('/{documentId}', [SpecDocumentController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('corpora')->group(function () {
        Route::get('/', [SpecCorpusController::class, 'index']);
        Route::get('/{corpusId}', [SpecCorpusController::class, 'show']);
    });

    Route::prefix('groups')->group(function () {
        Route::get('/', [SpecGroupController::class, 'index']);
        Route::post('/', [SpecGroupController::class, 'store']);
        Route::get('/{groupId}/users', [SpecGroupController::class, 'users'])->where('groupId', '.*');
        Route::put('/{groupId}/users', [SpecGroupController::class, 'replaceUsers'])->where('groupId', '.*');
        Route::patch('/{groupId}/users', [SpecGroupController::class, 'updateUsers'])->where('groupId', '.*');
        Route::get('/{groupId}', [SpecGroupController::class, 'show'])->where('groupId', '.*');
        Route::delete('/{groupId}', [SpecGroupController::class, 'destroy'])->where('groupId', '.*')->middleware('throttle:hawki-destructive');
    });

    Route::prefix('auth')->group(function () {
        Route::get('/check', [SpecAuthorizationController::class, 'check']);
        Route::get('/users/by-identifier/heaps', [SpecAuthorizationController::class, 'heapsByIdentifier']);
        Route::prefix('groups')->group(function () {
            Route::get('/', [SpecGroupController::class, 'index']);
            Route::post('/', [SpecGroupController::class, 'store']);
            Route::get('/{groupId}/users', [SpecGroupController::class, 'users'])->where('groupId', '.*');
            Route::put('/{groupId}/users', [SpecGroupController::class, 'replaceUsers'])->where('groupId', '.*');
            Route::patch('/{groupId}/users', [SpecGroupController::class, 'updateUsers'])->where('groupId', '.*');
            Route::get('/{groupId}', [SpecGroupController::class, 'show'])->where('groupId', '.*');
            Route::delete('/{groupId}', [SpecGroupController::class, 'destroy'])->where('groupId', '.*')->middleware('throttle:hawki-destructive');
        });
        Route::get('/heaps/{heapId}', [SpecAuthorizationController::class, 'heapGrants']);
        Route::put('/heaps/{heapId}', [SpecAuthorizationController::class, 'replaceHeapGrants']);
        Route::patch('/heaps/{heapId}', [SpecAuthorizationController::class, 'updateHeapGrants']);
        Route::delete('/heaps/{heapId}', [SpecAuthorizationController::class, 'deleteHeapGrants'])->middleware('throttle:hawki-destructive');
        Route::get('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'heapGrants']);
        Route::put('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'replaceHeapGrants']);
        Route::patch('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'updateHeapGrants']);
        Route::delete('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'deleteHeapGrants'])->middleware('throttle:hawki-destructive');
        Route::get('/documents/{documentId}', [SpecAuthorizationController::class, 'documentGrants']);
        Route::put('/documents/{documentId}', [SpecAuthorizationController::class, 'replaceDocumentGrants']);
        Route::patch('/documents/{documentId}', [SpecAuthorizationController::class, 'updateDocumentGrants']);
        Route::delete('/documents/{documentId}', [SpecAuthorizationController::class, 'deleteDocumentGrants'])->middleware('throttle:hawki-destructive');
        Route::get('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'documentGrants']);
        Route::put('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'replaceDocumentGrants']);
        Route::patch('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'updateDocumentGrants']);
        Route::delete('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'deleteDocumentGrants'])->middleware('throttle:hawki-destructive');
    });
});
