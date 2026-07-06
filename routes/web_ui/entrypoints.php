<?php

use App\Http\Controllers\SettingsController;
use App\Services\Profile\OperatorAccessService;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/swagger', fn () => redirect('/swagger/index.html'));

Route::get('/admin', fn () => view('svelte-page', [
    'title' => 'HAWKI-RAG Operator',
    'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
    'configScriptId' => 'hawki-rag-experience-config',
    'config' => $hawkiRagExperienceConfig('operator'),
    'rootAttributes' => ['data-hawki-rag-experience' => true],
]));

Route::get('/admin/pipeline', fn () => redirect('/pipeline-controller'));
Route::get('/admin/heaps', fn () => redirect('/heaps'));
Route::get('/admin/datasets', fn () => redirect('/heaps'));
Route::get('/admin/graph', fn () => redirect('/neo4j-graph-explorer'));
Route::get('/admin/search', fn () => redirect('/hawki-rag-search'));
Route::get('/admin/retrieve', fn () => redirect('/hawki-rag-search'));
Route::get('/admin/settings', fn () => redirect('/settings'));
Route::get('/admin/analytics', fn () => view('svelte-page', [
    'title' => 'HAWKI-RAG Operator',
    'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
    'configScriptId' => 'hawki-rag-experience-config',
    'config' => $hawkiRagExperienceConfig('analytics'),
    'rootAttributes' => ['data-hawki-rag-experience' => true],
]));
Route::get('/admin/health-repair', fn () => redirect('/pipeline-health'));

Route::get('/settings', [SettingsController::class, 'page']);
Route::middleware('operator')->group(function (): void {
    Route::get('/settings/config', [SettingsController::class, 'show']);
    Route::put('/settings/config', [SettingsController::class, 'update'])->middleware('throttle:hawki-destructive');
});
