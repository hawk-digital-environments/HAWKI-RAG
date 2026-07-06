<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/swagger');
Route::get('/swagger', fn () => redirect('/swagger/index.html'));
Route::middleware('operator')->group(function (): void {
    Route::get('/settings/config', [SettingsController::class, 'show']);
    Route::put('/settings/config', [SettingsController::class, 'update'])->middleware('throttle:hawki-destructive');
});
