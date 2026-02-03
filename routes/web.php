<?php

use App\Http\Controllers\AdminPanelController;
use App\Http\Controllers\ScrapeController;

use Illuminate\Support\Facades\Route;

// HAWKI RAG playground and chat helpers
Route::get('/chat', function () {
    return view('chat');
});

Route::get('/hawki-rag-playground', function () {
    return view('hawki-rag-playground', [
        'chatPrompt' => config('model_prompts.prompts.chat') ?? '',
        'ragPrompt'  => config('model_prompts.prompts.rag') ?? '',
    ]);
});


if (class_exists(\App\Http\Controllers\AiConvController::class)) {
    Route::get('/files/{uuid}/private/{path}', [\App\Http\Controllers\AiConvController::class, 'downloadAttachment'])
        ->where([
            'path' => '.*',
        ])->name('files.download')->middleware('signed');
}



Route::get('/', [AdminPanelController::class, 'index']);

Route::post('/requestScrape', [ScrapeController::class, 'requestScrape']);
Route::post('/cancelScrape', [ScrapeController::class, 'cancelScrape']);
Route::post('/getAllScrapes', [ScrapeController::class, 'getAllScrapes']);
Route::post('/deleteScrapeJob', [ScrapeController::class, 'deleteScrapeJob']);
Route::post('/deleteScrapeContent', [ScrapeController::class, 'deleteScrapeContent']);
Route::post('/getScrapeInformation', [ScrapeController::class, 'getScrapeInformation']);
Route::post('/getScrapeResult', [ScrapeController::class, 'getScrapeResult']);
Route::post('/extractPageContent', [ScrapeController::class, 'extractPageContent']);
