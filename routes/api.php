<?php

use App\Mcp\Servers\RawkiServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RawkiProxyController;

// Health check
Route::get('/ping', fn() => response()->json(['pong' => true]));
//Route::post('/rawki/query', RawkiServer::class);
