<?php

/*
|--------------------------------------------------------------------------
| Health and Monitoring Boundary
|--------------------------------------------------------------------------
| Health, Monitor, Ping und Liveness sind ein eigener Add-on Sector. Hier
| pruefen wir, ob die grossen RAWKI/RAG Bausteine sauber laufen, ohne die Core
| UI und die internen System APIs mit Health-Routing zu vermischen.
*/

/*
|--------------------------------------------------------------------------
| Health Controllers
|--------------------------------------------------------------------------
| HawkiRagSystemGateController: blockiert Operator UIs, bis Core Services gruen sind.
| RagHealthController: prueft, ob die RAG Bridge erreichbar ist.
| RagMonitorController: liefert Runtime- und Graph-Monitoring fuer die UI.
| PipelineHealthController: prueft Worker, Queues, Storage, Qdrant und Neo4j.
*/
use App\Http\Controllers\Health\HawkiRagSystemGateController;
use App\Http\Controllers\Health\PipelineHealthController;
use App\Http\Controllers\Health\RagHealthController;
use App\Http\Controllers\Health\RagMonitorController;

/*
|--------------------------------------------------------------------------
| Laravel Route Helper
|--------------------------------------------------------------------------
| Route: registriert Health Pages, Health Endpoints und Internal API Checks.
*/
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Liveness
|--------------------------------------------------------------------------
| Minimaler Laravel Liveness Check fuer Load Balancer, Docker und Uptime Tools.
*/
Route::get('/up', fn () => response()->noContent())->middleware('throttle:hawki-health');

/*
|--------------------------------------------------------------------------
| Web Health Surface
|--------------------------------------------------------------------------
| Browser/UI Health Add-ons. Die URLs bleiben bewusst stabil, damit bestehende
| Dashboards und Frontend Polling ohne Umbau weiterlaufen.
*/
Route::middleware(['web', 'throttle:hawki-health'])->group(function () {
    Route::get('/pipeline-health', function () {
        return view('svelte-page', [
            'title' => 'HAWKI Pipeline Health',
            'vite' => ['resources/css/pipeline-health-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/js/pipeline-health-dashboard.js'],
            'rootAttributes' => ['data-pipeline-health-dashboard' => true],
        ]);
    });

    Route::get('/pipeline/health', [PipelineHealthController::class, 'show']);
    Route::get('/rag/health', [RagHealthController::class, 'show']);
    Route::get('/rag/monitor', [RagMonitorController::class, 'show']);
    Route::get('/health/system-gate', [HawkiRagSystemGateController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Internal API Health Surface
|--------------------------------------------------------------------------
| Token-geschuetzte Health Checks fuer interne Clients, Bruno, Scripts und
| System-to-System Calls. Laravel mountet diese Gruppe weiterhin unter /api.
*/
Route::middleware(['api', 'auth:sanctum', 'throttle:hawki-api'])->prefix('api')->group(function () {
    Route::get('/ping', fn () => response()->json(['pong' => true]));
    Route::get('/pipeline/health', [PipelineHealthController::class, 'show']);
    Route::get('/rag/health', [RagHealthController::class, 'show']);
    Route::get('/rag/monitor', [RagMonitorController::class, 'show']);
    Route::get('/health/system-gate', [HawkiRagSystemGateController::class, 'show']);
});
