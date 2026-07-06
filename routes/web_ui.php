<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:hawki-ui')->group(function (): void {
    require __DIR__.'/web_ui/entrypoints.php';
});
