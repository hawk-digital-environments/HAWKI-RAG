<?php

/*
|--------------------------------------------------------------------------
| Web UI Routes
|--------------------------------------------------------------------------
| Hier liegen die UI-Seiten fuer den Browser und die RAG Endpoints, die direkt
| von der UI genutzt werden. Interne Service-to-Service APIs bleiben sauber in
| routes/internal_api.php.
*/

/*
|--------------------------------------------------------------------------
| RAG Playground UI Endpoints
|--------------------------------------------------------------------------
| HawkiRagProxyController: sendet Benutzeranfragen aus der UI an die RAG-Bridge.
| RagStatsController: liefert Vektor-/Qdrant-Statistiken und kuemmert sich um Collection Cleanup.
*/
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\RagStatsController;

/*
|--------------------------------------------------------------------------
| Dataset and Document UI Endpoints
|--------------------------------------------------------------------------
| DatasetController: versorgt Dataset-Seiten und Storage Cleanup Actions.
| DocumentBrowserController: versorgt Dokumentlisten und Document Detail Drawers.
*/
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;

/*
|--------------------------------------------------------------------------
| Graph Explorer UI Endpoints
|--------------------------------------------------------------------------
| RagGraphController: versorgt Neo4j Graph Search, Expansion, Snapshots und Reset.
*/
use App\Http\Controllers\Graph\RagGraphController;

/*
|--------------------------------------------------------------------------
| Pipeline and Scraper UI Endpoints
|--------------------------------------------------------------------------
| PipelineControlController: verarbeitet File Uploads aus der UI fuer Ingestion.
| PipelineRecoveryController: treibt Retry Controls fuer fehlgeschlagene Pipeline Jobs an.
| PipelineStatusController: stellt Job-Status Lookups bereit.
| PipelineTaskController: versorgt Task Lists, Jobs, Events, Retries, Cancel
| und Delete Actions.
| ScrapeController: steuert aktive Scraper Tasks und Jobs.
*/
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;
use App\Http\Controllers\ScrapeTaskUiProxyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UploadedSourceDocumentController;
use App\Services\Profile\OperatorAccessService;
use App\Services\Settings\SettingsService;

/*
|--------------------------------------------------------------------------
| Laravel Route Helper
|--------------------------------------------------------------------------
| Route: registriert Browser Routes, Endpoint Paths, HTTP Verbs und Redirects.
*/
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Entry Points and API Documentation
|--------------------------------------------------------------------------
| Root Routes, die selbst kein eigenes Dashboard besitzen.
*/
$hawkiRagExperienceConfig = static function (string $section = 'operator'): array {
    return [
        'activeSection' => $section,
        'operatorRoutes' => [
            [
                'key' => 'pipeline',
                'label' => 'Pipeline',
                'title' => 'Pipeline Controller',
                'href' => '/admin/pipeline',
                'summary' => 'Run ingestion, uploads, conversion, retries, and live stage logs.',
                'service' => 'Pipeline',
                'state' => 'live',
            ],
            [
                'key' => 'datasets',
                'label' => 'Datasets',
                'title' => 'Dataset Browser',
                'href' => '/admin/datasets',
                'summary' => 'Browse stored documents, datasets, sources, and recovery context.',
                'service' => 'Dataset browser',
                'state' => 'ready',
            ],
            [
                'key' => 'graph',
                'label' => 'Graph',
                'title' => 'Graph Explorer',
                'href' => '/admin/graph',
                'summary' => 'Inspect entities, relations, neighborhoods, and graph paths.',
                'service' => 'Graph retrieval',
                'state' => 'live',
            ],
            [
                'key' => 'retrieve',
                'label' => 'Retrieve',
                'title' => 'Retrieval Playground',
                'href' => '/admin/retrieve',
                'summary' => 'Ask questions, compare vector and graph answers, and inspect evidence.',
                'service' => 'Retrieval console',
                'state' => 'live',
            ],
        ],
    ];
};

$pipelineControllerConfig = static function (): array {
    $normalizeExtensions = static fn (array $extensions): array => collect($extensions)
        ->map(fn ($extension) => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values()
        ->all();
    $customConverter = app(SettingsService::class)->customConverterUploadDefaults();

    return [
        'nativeExtensions' => $normalizeExtensions(config('file_converter.raganything_supported_extensions', [])),
        'customExtensions' => $normalizeExtensions(config('file_converter.supported_extensions', [])),
        'customConverter' => [
            'enabled' => (bool) ($customConverter['enabled'] ?? false),
            'configured' => (bool) ($customConverter['configured'] ?? false),
            'supported_extensions' => $normalizeExtensions($customConverter['supported_extensions'] ?? []),
        ],
    ];
};

Route::middleware('throttle:hawki-ui')->group(function () use ($hawkiRagExperienceConfig, $pipelineControllerConfig): void {
Route::redirect('/', '/admin');
Route::get('/swagger', fn () => redirect('/swagger/index.html'));

/*
|--------------------------------------------------------------------------
| UI Page: HAWKI-RAG Operator Experience (/admin)
|--------------------------------------------------------------------------
| Svelte Experience Hub fuer Operator Tabs. Bestehende Dashboards bleiben
| stabile Zielseiten fuer die einzelnen Operator-Flows.
*/
Route::get('/admin', fn () => view('svelte-page', [
    'title' => 'HAWKI-RAG Operator',
    'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
    'configScriptId' => 'hawki-rag-experience-config',
    'config' => $hawkiRagExperienceConfig('operator'),
    'rootAttributes' => ['data-hawki-rag-experience' => true],
]));

/*
|--------------------------------------------------------------------------
| Operator World Aliases
|--------------------------------------------------------------------------
| Admin URLs fuer Entwicklung und Betrieb. Sie beschreiben die Aufgabe klar
| und leiten auf die produktiven Dashboards.
*/
Route::get('/admin/pipeline', fn () => redirect('/pipeline-controller'));
Route::get('/admin/datasets', fn () => redirect('/datasets'));
Route::get('/admin/graph', fn () => redirect('/neo4j-graph-explorer'));
Route::get('/admin/retrieve', fn () => redirect('/hawki-rag-playground'));
Route::get('/admin/settings', fn () => redirect('/settings'));
Route::get('/admin/analytics', fn () => view('svelte-page', [
    'title' => 'HAWKI-RAG Operator',
    'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
    'configScriptId' => 'hawki-rag-experience-config',
    'config' => $hawkiRagExperienceConfig('analytics'),
    'rootAttributes' => ['data-hawki-rag-experience' => true],
]));
Route::get('/admin/health-repair', fn () => redirect('/pipeline-health'));

/*
|--------------------------------------------------------------------------
| UI Page: Settings (/settings)
|--------------------------------------------------------------------------
| Operator defaults for converter profiles and model runtime choices.
*/
Route::get('/settings', [SettingsController::class, 'page']);
Route::middleware('operator')->group(function (): void {
    Route::get('/settings/config', [SettingsController::class, 'show']);
    Route::put('/settings/config', [SettingsController::class, 'update'])->middleware('throttle:hawki-destructive');
});

/*
|--------------------------------------------------------------------------
| UI Page: HAWKI RAG Playground (/hawki-rag-playground)
|--------------------------------------------------------------------------
| Chat, RAG Status Cards, Qdrant Stats und die zentrale Playground Shell.
*/
Route::get('/hawki-rag-playground', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'HAWKI-RAG Console',
        'vite' => 'resources/js/hawki-rag-playground.js',
        'configScriptId' => 'hawki-rag-playground-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-hawki-rag-playground' => true],
    ]);
});
Route::middleware(['operator', 'auth:sanctum'])->group(function (): void {
    Route::get('/query/datasets', [HawkiRagProxyController::class, 'datasets']);
    Route::post('/query', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
});
Route::middleware('operator')->group(function (): void {
    Route::get('/rag/stats', [RagStatsController::class, 'show']);
    Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])->middleware('throttle:hawki-destructive');
});

/*
|--------------------------------------------------------------------------
| UI Page: Neo4j Graph Explorer (/neo4j-graph-explorer)
|--------------------------------------------------------------------------
| Graph Visualization, Search, Node Expansion, Snapshots und Graph Reset.
*/
Route::get('/neo4j-graph-explorer', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'Neo4j Graph Explorer',
        'vite' => ['resources/css/app.css', 'resources/js/neo4j-graph-dashboard.js'],
        'configScriptId' => 'neo4j-graph-dashboard-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-neo4j-graph-dashboard' => true],
    ]);
});
Route::middleware('operator')->group(function (): void {
    Route::get('/rag/neo4j/graph/overview', [RagGraphController::class, 'overview']);
    Route::get('/rag/neo4j/graph/search', [RagGraphController::class, 'search']);
    Route::get('/rag/neo4j/graph/semantic-search', [RagGraphController::class, 'semanticSearch']);
    Route::get('/rag/neo4j/graph/node', [RagGraphController::class, 'node']);
    Route::post('/rag/neo4j/graph/expand', [RagGraphController::class, 'expand']);
    Route::post('/rag/neo4j/graph/clear-view', [RagGraphController::class, 'clearView']);
    Route::get('/rag/neo4j/graph/snapshots', [RagGraphController::class, 'snapshots']);
    Route::post('/rag/neo4j/graph/snapshots', [RagGraphController::class, 'saveSnapshot']);
    Route::get('/rag/neo4j/graph/snapshots/{id}', [RagGraphController::class, 'loadSnapshot']);
    Route::delete('/rag/neo4j/graph/snapshots/{id}', [RagGraphController::class, 'deleteSnapshot']);
    Route::post('/rag/neo4j/clear', [RagGraphController::class, 'clearNeo4j'])->middleware('throttle:hawki-destructive');
});

/*
|--------------------------------------------------------------------------
| UI Page: Pipeline Controller (/pipeline-controller)
|--------------------------------------------------------------------------
| File Upload Ingestion, Scraper Task Selection und die Pipeline Task Run List.
| Pipeline Task Endpoints werden von Controller, Dataset Browser und Recovery Controls genutzt.
*/
Route::get('/pipeline-controller', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) use ($pipelineControllerConfig) {
    return view('svelte-page', [
        'title' => 'HAWKI Pipeline Controller',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/pipeline-controller.js'],
        'configScriptId' => 'pipeline-controller-config',
        'config' => [
            ...$pipelineControllerConfig(),
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-pipeline-controller-dashboard' => true],
    ]);
});
Route::middleware('operator')->group(function (): void {
    Route::get('/scraper/jobs', [ScrapeController::class, 'getCrawlerJobs']);
    Route::get('/scraper/tasks', [ScrapeController::class, 'getCrawlerTasks']);
    Route::post('/scraper/tasks/start', [ScrapeController::class, 'startCrawlerTask'])->middleware('throttle:hawki-upload');
    Route::get('/scraper/status/{jobId}', [ScrapeController::class, 'getCrawlerStatus']);
    Route::post('/scraper/jobs/{jobId}/cancel', [ScrapeController::class, 'cancelCrawlerJob']);
    Route::post('/scraper/jobs/{jobId}/pause', [ScrapeController::class, 'pauseCrawlerJob']);
    Route::post('/scraper/jobs/{jobId}/resume', [ScrapeController::class, 'resumeCrawlerJob']);
    Route::get('/pipeline/status/{jobId}', [PipelineStatusController::class, 'show']);
    Route::get('/pipeline/tasks', [PipelineTaskController::class, 'index']);
    Route::post('/pipeline/tasks/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
    Route::get('/pipeline/tasks/{taskId}', [PipelineTaskController::class, 'show']);
    Route::get('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
    Route::get('/pipeline/tasks/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
    Route::get('/pipeline/tasks/{taskId}/events', [PipelineTaskController::class, 'events']);
    Route::get('/pipeline/tasks/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
    Route::get('/pipeline/tasks/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
    Route::post('/pipeline/tasks/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
    Route::post('/pipeline/tasks/{taskId}/retry', [PipelineTaskController::class, 'retry'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/tasks/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/tasks/{taskId}/cancel', [PipelineTaskController::class, 'cancel'])->middleware('throttle:hawki-destructive');
    Route::delete('/pipeline/tasks/{taskId}', [PipelineTaskController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/controller/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
});
Route::any('/ui/{path?}', ScrapeTaskUiProxyController::class)
    ->where('path', '.*');

/*
|--------------------------------------------------------------------------
| UI Page: Datasets Dashboard (/datasets)
|--------------------------------------------------------------------------
| Dataset Browser, Document Browser Drawer, Dataset Storage Cleanup und
| Retry Actions aus Dataset-/Task-Details.
*/
Route::get('/datasets', function (\Illuminate\Http\Request $request, OperatorAccessService $operatorAccess) {
    return view('svelte-page', [
        'title' => 'HAWKI Data Browser',
        'vite' => ['resources/css/datasets-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/css/hawki-rag-theme.css', 'resources/js/datasets-dashboard.js'],
        'configScriptId' => 'datasets-dashboard-config',
        'config' => [
            'operatorAuthorized' => $operatorAccess->allows($request),
        ],
        'rootAttributes' => ['data-datasets-dashboard' => true],
    ]);
});
Route::middleware('operator')->group(function (): void {
    Route::get('/datasets/data', [DatasetController::class, 'index']);
    Route::get('/datasets/data/{datasetId}', [DatasetController::class, 'show']);
    Route::delete('/datasets/data/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])->middleware('throttle:hawki-destructive');
});
Route::get('/documents', function (\Illuminate\Http\Request $request) {
    $query = $request->getQueryString();

    return redirect('/datasets'.($query ? '?'.$query : ''));
});
Route::middleware('operator')->group(function (): void {
    Route::get('/documents/data', [DocumentBrowserController::class, 'index']);
    Route::get('/documents/data/{documentId}', [DocumentBrowserController::class, 'show']);
    Route::get('/documents/uploads/download', UploadedSourceDocumentController::class)
        ->name('documents.uploads.download');
});

/*
|--------------------------------------------------------------------------
| Shared UI APIs: Pipeline Recovery and Task Manager
|--------------------------------------------------------------------------
| Wird von den Retry Controls im Dataset Browser und von Recovery-Actions genutzt.
*/
Route::middleware('operator')->group(function (): void {
    Route::get('/pipeline/recovery/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
    Route::post('/pipeline/recovery/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/retry-all', [PipelineRecoveryController::class, 'retryAll'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])->middleware('throttle:hawki-destructive');
    Route::post('/pipeline/recovery/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
});

});
