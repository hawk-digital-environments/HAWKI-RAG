<?php

use Illuminate\Support\Facades\Route;

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
