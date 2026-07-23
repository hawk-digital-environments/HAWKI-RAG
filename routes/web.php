<?php

declare(strict_types=1);

use App\Http\Controllers\ScrapeTaskUiProxyController;
use App\Http\Controllers\SettingsController;
use App\Services\Authorization\BrowserQueryPrincipalService;
use App\Services\Profile\OperatorAccessService;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared Operator Navigation Configuration
|--------------------------------------------------------------------------
| This presentation-only payload drives the operator landing page. Service
| URLs are page links; each page calls the canonical /api endpoints for data
| and actions instead of registering JSON routes in this file.
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
| may render a locked state for unauthenticated visitors, but every data read
| or mutation is independently authorized by the matching /api route.
*/
Route::middleware('throttle:hawki-ui')->group(function () use ($hawkiRagExperienceConfig, $pipelineControllerConfig): void {
    /*
    |----------------------------------------------------------------------
    | Entry Points and API Documentation
    |----------------------------------------------------------------------
    | The project root opens the operator experience. Swagger remains a page
    | redirect to the generated API documentation assets.
    */
    Route::redirect('/', '/admin');
    Route::get('/swagger', static fn (): RedirectResponse => redirect('/swagger/index.html'));

    /*
    |----------------------------------------------------------------------
    | Operator Experience Pages
    |----------------------------------------------------------------------
    | /admin and /admin/analytics render the shared Svelte navigation shell
    | with the active product section selected in the bootstrap payload.
    */
    Route::get('/admin', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI-RAG Operator',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
        'configScriptId' => 'hawki-rag-experience-config',
        'config' => $hawkiRagExperienceConfig('operator'),
        'rootAttributes' => ['data-hawki-rag-experience' => true],
    ]));
    Route::get('/admin/analytics', static fn (): View => view('svelte-page', [
        'title' => 'HAWKI-RAG Operator',
        'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/hawki-rag-experience.js'],
        'configScriptId' => 'hawki-rag-experience-config',
        'config' => $hawkiRagExperienceConfig('analytics'),
        'rootAttributes' => ['data-hawki-rag-experience' => true],
    ]));

    /*
    |----------------------------------------------------------------------
    | Stable Operator Navigation Aliases
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
    | SettingsController renders sanitized initial state and whether the
    | current browser may use operator APIs. Reads and updates happen only at
    | /api/settings/config.
    */
    Route::get('/settings', [SettingsController::class, 'page']);

    /*
    |----------------------------------------------------------------------
    | Retrieval Playground Page
    |----------------------------------------------------------------------
    | The shell supports two independent capabilities: dataset-scoped query
    | access and broader operator controls. The API enforces both capabilities;
    | these booleans only decide which Svelte controls should be rendered.
    */
    Route::get('/hawki-rag-playground', function (
        Request $request,
        OperatorAccessService $operatorAccess,
        BrowserQueryPrincipalService $queryPrincipals,
    ): View {
        return view('svelte-page', [
            'title' => 'HAWKI-RAG Console',
            'vite' => 'resources/js/hawki-rag-playground.js',
            'configScriptId' => 'hawki-rag-playground-config',
            'config' => [
                'operatorAuthorized' => $operatorAccess->allows($request),
                'queryAuthenticated' => $queryPrincipals->resolve($request) !== null,
            ],
            'rootAttributes' => ['data-hawki-rag-playground' => true],
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Knowledge Graph Explorer Page
    |----------------------------------------------------------------------
    | Renders the graph workspace shell. Graph data is loaded lazily from the
    | operator-protected /api/rag/neo4j endpoints only when access is allowed.
    */
    Route::get('/neo4j-graph-explorer', function (Request $request, OperatorAccessService $operatorAccess): View {
        return view('svelte-page', [
            'title' => 'Neo4j Graph Explorer',
            'vite' => ['resources/css/app.css', 'resources/js/neo4j-graph-dashboard.js'],
            'configScriptId' => 'neo4j-graph-dashboard-config',
            'config' => ['operatorAuthorized' => $operatorAccess->allows($request)],
            'rootAttributes' => ['data-neo4j-graph-dashboard' => true],
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Pipeline Controller Page
    |----------------------------------------------------------------------
    | Supplies safe upload/converter capabilities and an authorization flag to
    | the Svelte shell. Pipeline tasks, uploads, and recovery actions all use
    | the canonical /api/pipeline and /api/scraper domains.
    */
    Route::get('/pipeline-controller', function (
        Request $request,
        OperatorAccessService $operatorAccess,
        SettingsService $settings,
    ) use ($pipelineControllerConfig): View {
        return view('svelte-page', [
            'title' => 'HAWKI Pipeline Controller',
            'vite' => ['resources/css/app.css', 'resources/css/hawki-rag-theme.css', 'resources/js/pipeline-controller.js'],
            'configScriptId' => 'pipeline-controller-config',
            'config' => [
                ...$pipelineControllerConfig($settings),
                'operatorAuthorized' => $operatorAccess->allows($request),
            ],
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
    Route::get('/datasets', function (Request $request, OperatorAccessService $operatorAccess): View {
        return view('svelte-page', [
            'title' => 'HAWKI Data Browser',
            'vite' => ['resources/css/datasets-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/css/hawki-rag-theme.css', 'resources/js/datasets-dashboard.js'],
            'configScriptId' => 'datasets-dashboard-config',
            'config' => ['operatorAuthorized' => $operatorAccess->allows($request)],
            'rootAttributes' => ['data-datasets-dashboard' => true],
        ]);
    });

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
    | duplicate API; crawler actions exposed by RAWKI remain under /api.
    */
    Route::any('/ui/{path?}', ScrapeTaskUiProxyController::class)
        ->where('path', '.*');
});
