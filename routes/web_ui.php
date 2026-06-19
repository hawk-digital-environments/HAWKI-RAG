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
| PipelineStatusController: stellt Legacy Job-Status Lookups bereit.
| PipelineTaskController: versorgt Task Lists, Jobs, Events, Retries, Cancel
| und Delete Actions.
| ScrapeController: steuert Scraper Tasks/Jobs und Legacy Scrape Endpoints.
*/
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineStatusController;
use App\Http\Controllers\PipelineTaskController;
use App\Http\Controllers\ScrapeController;

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
                'key' => 'operator',
                'label' => 'Admin',
                'title' => 'Operator Dashboard',
                'href' => '/admin',
                'summary' => 'Control surface for the HAWKI-RAG stack.',
                'service' => 'Experience shell',
                'state' => 'ready',
            ],
            [
                'key' => 'pipeline',
                'label' => 'Pipeline',
                'title' => 'Pipeline Controller',
                'href' => '/admin/pipeline',
                'summary' => 'Upload, ingest, cancel, retry, and delete task cache.',
                'service' => 'Pipeline',
                'state' => 'live',
            ],
            [
                'key' => 'datasets',
                'label' => 'Datasets',
                'title' => 'Dataset Browser',
                'href' => '/admin/datasets',
                'summary' => 'Documents, dataset storage, and recovery actions.',
                'service' => 'Dataset browser',
                'state' => 'ready',
            ],
            [
                'key' => 'graph',
                'label' => 'Graph',
                'title' => 'Graph Explorer',
                'href' => '/admin/graph',
                'summary' => 'Neo4j graph world and technical graph inspection.',
                'service' => 'Graph retrieval',
                'state' => 'live',
            ],
            [
                'key' => 'analytics',
                'label' => 'Analytics',
                'title' => 'Internal Analytics',
                'href' => '/admin/analytics',
                'summary' => 'Topic trends, graph depth, and source coverage.',
                'service' => 'Analytics',
                'state' => 'planned',
            ],
            [
                'key' => 'health',
                'label' => 'Repair',
                'title' => 'Health/Repair',
                'href' => '/admin/health-repair',
                'summary' => 'RAG bridge, Qdrant, Neo4j, pipeline, and storage checks.',
                'service' => 'Health/Repair',
                'state' => 'live',
            ],
        ],
        'coreServices' => [
            [
                'key' => 'retrieval',
                'label' => 'Retrieval',
                'title' => 'RAG-Anything with Qdrant.',
                'state' => 'live',
            ],
            [
                'key' => 'graph-retrieval',
                'label' => 'Graph Retrieval',
                'title' => 'Qdrant plus Neo4j graph retrieval.',
                'state' => 'live',
            ],
            [
                'key' => 'proxy-pointer',
                'label' => 'HAWKI-RAG-PRO',
                'title' => 'Proxy Pointer retrieval path.',
                'state' => 'planned',
            ],
            [
                'key' => 'analytics',
                'label' => 'Analytics',
                'title' => 'Scientific graph and source analysis.',
                'state' => 'planned',
            ],
            [
                'key' => 'health-repair',
                'label' => 'Health/Repair',
                'title' => 'System diagnosis and recovery.',
                'state' => 'live',
            ],
        ],
    ];
};

Route::middleware('throttle:hawki-ui')->group(function () use ($hawkiRagExperienceConfig): void {
Route::redirect('/', '/admin');
Route::get('/swagger', fn () => redirect('/swagger/index.html'));

/*
|--------------------------------------------------------------------------
| UI Page: HAWKI-RAG Operator Experience (/admin)
|--------------------------------------------------------------------------
| Svelte Experience Hub fuer Operator Tabs. Bestehende Dashboards bleiben
| stabile Zielseiten fuer die einzelnen Operator-Flows.
*/
Route::get('/admin', fn () => view('hawki-rag', [
    'experience' => $hawkiRagExperienceConfig('operator'),
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
Route::get('/admin/analytics', fn () => view('hawki-rag', [
    'experience' => $hawkiRagExperienceConfig('analytics'),
]));
Route::get('/admin/health-repair', fn () => redirect('/pipeline-health'));

/*
|--------------------------------------------------------------------------
| UI Page: HAWKI RAG Playground (/hawki-rag-playground)
|--------------------------------------------------------------------------
| Chat, RAG Status Cards, Qdrant Stats und die zentrale Playground Shell.
*/
Route::get('/hawki-rag-playground', function () {
    return view('hawki-rag-playground', [
        'chatPrompt' => config('model_prompts.prompts.chat') ?? '',
        'ragPrompt'  => config('model_prompts.prompts.rag') ?? '',
    ]);
});
Route::post('/query', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
Route::get('/rag/stats', [RagStatsController::class, 'show']);
Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])->middleware('throttle:hawki-destructive');

/*
|--------------------------------------------------------------------------
| UI Page: Neo4j Graph Explorer (/neo4j-graph-explorer)
|--------------------------------------------------------------------------
| Graph Visualization, Search, Node Expansion, Snapshots und Graph Reset.
*/
Route::get('/neo4j-graph-explorer', function () {
    return view('neo4j-graph-dashboard');
});
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

/*
|--------------------------------------------------------------------------
| UI Page: Pipeline Controller (/pipeline-controller)
|--------------------------------------------------------------------------
| File Upload Ingestion, Scraper Task Selection und die Pipeline Task Run List.
| Pipeline Task Endpoints koennen auch von Task-Manager-Style Views genutzt werden.
*/
Route::get('/pipeline-controller', function () {
    return view('pipeline-controller-dashboard');
});
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

/*
|--------------------------------------------------------------------------
| UI Page: Datasets Dashboard (/datasets)
|--------------------------------------------------------------------------
| Dataset Browser, Document Browser Drawer, Dataset Storage Cleanup und
| Retry Actions aus Dataset-/Task-Details.
*/
Route::get('/datasets', function () {
    return view('datasets-dashboard');
});
Route::get('/datasets/data', [DatasetController::class, 'index']);
Route::get('/datasets/data/{datasetId}', [DatasetController::class, 'show']);
Route::delete('/datasets/data/{datasetId}/storage', [DatasetController::class, 'destroyStorage'])->middleware('throttle:hawki-destructive');
Route::get('/documents', function (\Illuminate\Http\Request $request) {
    $query = $request->getQueryString();

    return redirect('/datasets'.($query ? '?'.$query : ''));
});
Route::get('/documents/data', [DocumentBrowserController::class, 'index']);
Route::get('/documents/data/{documentId}', [DocumentBrowserController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Shared UI APIs: Pipeline Recovery and Task Manager
|--------------------------------------------------------------------------
| Wird von den Retry Controls im Datasets Dashboard und von Task-Manager Scripts genutzt.
*/
Route::get('/pipeline/recovery/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
Route::post('/pipeline/recovery/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])->middleware('throttle:hawki-destructive');
Route::post('/pipeline/recovery/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])->middleware('throttle:hawki-destructive');
Route::post('/pipeline/recovery/retry-all', [PipelineRecoveryController::class, 'retryAll'])->middleware('throttle:hawki-destructive');
Route::post('/pipeline/recovery/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])->middleware('throttle:hawki-destructive');
Route::post('/pipeline/recovery/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');

/*
|--------------------------------------------------------------------------
| Legacy Scraper API Surface
|--------------------------------------------------------------------------
| Aeltere Scraper Controls bleiben fuer Legacy Playground Widgets und direkte
| Caller verfuegbar.
*/
Route::post('/requestScrape', [ScrapeController::class, 'requestScrape'])->middleware('throttle:hawki-upload');
Route::post('/cancelScrape', [ScrapeController::class, 'cancelScrape'])->middleware('throttle:hawki-destructive');
Route::post('/getAllScrapes', [ScrapeController::class, 'getAllScrapes']);
Route::post('/deleteScrapeJob', [ScrapeController::class, 'deleteScrapeJob'])->middleware('throttle:hawki-destructive');
Route::post('/deleteScrapeContent', [ScrapeController::class, 'deleteScrapeContent'])->middleware('throttle:hawki-destructive');
Route::post('/getScrapeInformation', [ScrapeController::class, 'getScrapeInformation']);
Route::post('/getScrapeResult', [ScrapeController::class, 'getScrapeResult']);
Route::post('/extractPageContent', [ScrapeController::class, 'extractPageContent']);
});
