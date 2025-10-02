<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\QdrantSearchController; // stream route 
use App\Http\Controllers\API\QdrantTestController;   // new JSON test route
use App\Http\Controllers\API\RawkiProxyController;

// Route::post('/search', [SearchController::class, 'search'])
//     // ->middleware(['serverAuthentication'])
//     ->name('api.search');
// Route::post('/qdrantsearch', [QdrantSearchController::class, 'search'])
//     // ->middleware(['serverAuthentication'])
//     ->name('api.qdrantsearch');

Route::post('/qdrant-search', [QdrantSearchController::class, 'search']);
// Health check
Route::get('/ping', fn() => response()->json(['pong' => true]));
Route::post('/qdrant-test', [QdrantTestController::class, 'test']);
Route::post('/rawki/query', [RawkiProxyController::class, 'query']);
