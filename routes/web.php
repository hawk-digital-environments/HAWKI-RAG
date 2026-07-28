<?php

declare(strict_types=1);

use App\Http\Controllers\ScrapeTaskUiProxyController;
use App\Http\Controllers\SettingsController;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared Admin Navigation Configuration
|--------------------------------------------------------------------------
| This presentation-only payload drives the admin landing page. Service
| URLs are page links; each page calls the canonical /api endpoints for data
| and actions instead of registering JSON routes in this file.
*/
$hawkiRagExperienceConfig = static function (string $section = 'admin'): array {
    return [
        'activeSection' => $section,
        'adminRoutes' => [
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

/*
|--------------------------------------------------------------------------
| Pipeline Page Bootstrap Configuration
|--------------------------------------------------------------------------
| The page needs safe converter capabilities before its JavaScript runtime
| starts. SettingsService supplies sanitized defaults; secrets are never
| embedded into the browser document.
*/
$pipelineControllerConfig = static function (SettingsService $settings): array {
    $normalizeExtensions = static fn (array $extensions): array => collect($extensions)
        ->map(static fn ($extension): string => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values()
        ->all();
    $customConverter = $settings->customConverterUploadDefaults();

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

/*
|--------------------------------------------------------------------------
| Browser Page Boundary
|--------------------------------------------------------------------------
| Only HTML pages, redirects, and the crawler UI transport live here. Pages
| render their management workspaces directly, while dataset-scoped retrieval
| remains independently authorized by its matching /api routes.
*/
Route::middleware('throttle:hawki-ui')->group(function () use ($hawkiRagExperienceConfig, $pipelineControllerConfig): void {
    /*
    |----------------------------------------------------------------------
    | Entry Points and API Documentation
    |----------------------------------------------------------------------
    | The project root opens the admin experience. Swagger remains a page
    | redirect to the generated API documentation assets.
    */
    Route::redirect('/', '/admin');
    Route::get('/swagger', static function (): RedirectResponse {
        $basePath = '/'.trim((string) config('app.asset_base_path', '/'), '/');
        $swaggerPath = ($basePath === '/' ? '' : $basePath).'/swagger/index.html';

        return redirect($swaggerPath);
    });

    /*
    |----------------------------------------------------------------------
    | Admin Experience Pages
    |----------------------------------------------------------------------
    | /admin and /admin/analytics render the shared Svelte navigation shell
    | with the active product section selected in the bootstrap payload.
    */
    Route::get('/admin', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI-RAG Admin',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
        'configScriptId' => 'hawki-rag-experience-config',
        'config' => $hawkiRagExperienceConfig('admin'),
        'rootAttributes' => ['data-hawki-rag-experience' => true],
    ]));
    Route::get('/admin/analytics', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI-RAG Admin',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
        'configScriptId' => 'hawki-rag-experience-config',
        'config' => $hawkiRagExperienceConfig('analytics'),
        'rootAttributes' => ['data-hawki-rag-experience' => true],
    ]));

    /*
    |----------------------------------------------------------------------
    | Stable Admin Navigation Aliases
    |----------------------------------------------------------------------
    | Task-oriented /admin URLs remain stable bookmark targets even when the
    | underlying page implementation has a more specific historical path.
    */
    Route::get('/admin/pipeline', static fn (): RedirectResponse => redirect('/pipeline-controller'));
    Route::get('/admin/datasets', static fn (): RedirectResponse => redirect('/datasets'));
    Route::get('/admin/graph', static fn (): RedirectResponse => redirect('/neo4j-graph-explorer'));
    Route::get('/admin/retrieve', static fn (): RedirectResponse => redirect('/hawki-rag-playground'));
    Route::get('/admin/settings', static fn (): RedirectResponse => redirect('/settings'));
    Route::get('/admin/health-repair', static fn (): RedirectResponse => redirect('/pipeline-health'));

    /*
    |----------------------------------------------------------------------
    | Settings Page
    |----------------------------------------------------------------------
    | SettingsController renders sanitized initial state. Reads and updates
    | happen only at /api/settings/config.
    */
    Route::get('/settings', [SettingsController::class, 'page']);

    /*
    |----------------------------------------------------------------------
    | Retrieval Playground Page
    |----------------------------------------------------------------------
    | The browser loads query-ready datasets from the canonical retrieval API.
    | The API resolves the deployment's query principal server-side.
    */
    Route::get('/hawki-rag-playground', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI-RAG Console',
        'vite' => 'resources/js/hawki-rag-playground.js',
        'rootAttributes' => ['data-hawki-rag-playground' => true],
    ]));

    /*
    |----------------------------------------------------------------------
    | Knowledge Graph Explorer Page
    |----------------------------------------------------------------------
    | Renders the graph workspace shell. Graph data is loaded lazily from the
    | throttled /api/rag/neo4j endpoints.
    */
    Route::get('/neo4j-graph-explorer', static function (): View {
        return view('svelte-page', [
            'title' => 'Neo4j Graph Explorer',
            'vite' => ['resources/css/app.css', 'resources/js/neo4j-graph-dashboard.js'],
            'rootAttributes' => ['data-neo4j-graph-dashboard' => true],
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Pipeline Controller Page
    |----------------------------------------------------------------------
    | Supplies safe upload/converter capabilities to the Svelte shell. Pipeline
    | tasks, uploads, and recovery actions all use the canonical /api/pipeline
    | and /api/scraper domains.
    */
    Route::get('/pipeline-controller', function (
        SettingsService $settings,
    ) use ($pipelineControllerConfig): View {
        return view('svelte-page', [
            'title' => 'HAWKI Pipeline Controller',
            'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/pipeline-controller.js'],
            'configScriptId' => 'pipeline-controller-config',
            'config' => $pipelineControllerConfig($settings),
            'rootAttributes' => ['data-pipeline-controller-dashboard' => true],
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Dataset and Document Browser Page
    |----------------------------------------------------------------------
    | One Svelte page owns dataset lists and document detail drawers. The
    | /documents URL is retained as a page-level alias and preserves filters;
    | all dataset/document payloads come from /api/datasets and /api/documents.
    */
    Route::get('/datasets', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI Data Browser',
        'vite' => ['resources/css/datasets-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/css/hawki-rag-theme.css', 'resources/js/datasets-dashboard.js'],
        'rootAttributes' => ['data-datasets-dashboard' => true],
    ]));

    Route::get('/documents', static function (Request $request): RedirectResponse {
        $query = $request->getQueryString();

        return redirect('/datasets'.($query ? '?'.$query : ''));
    });

    /*
    |----------------------------------------------------------------------
    | Embedded Crawler UI Transport
    |----------------------------------------------------------------------
    | This final wildcard transparently proxies the separately built crawler
    | UI and its relative assets. It is a browser transport boundary, not a
    | duplicate API; crawler actions exposed by HAWKI-RAG remain under /api.
    */
    Route::any('/ui/{path?}', ScrapeTaskUiProxyController::class)
        ->where('path', '.*');
});
