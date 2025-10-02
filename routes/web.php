<?php

use App\Http\Controllers\Web\HomeController;
use Illuminate\Support\Facades\Route;

// Root route for the homepage = search page
Route::get('/', [HomeController::class, 'index'])->name('search.index');

// Root route for lazy loading  
Route::get('/loadmore', [HomeController::class, 'loadMore'])->name('search.loadMore');

// RAWKI playground and chat helpers
Route::get('/chat', function () {
    return view('chat');
});

Route::get('/rawki-playground', function () {
    return view('rawki-playground', [
        'chatPrompt' => config('model_prompts.prompts.chat') ?? '',
        'ragPrompt'  => config('model_prompts.prompts.rag') ?? '',
    ]);
});
