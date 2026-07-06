<?php

/*
|--------------------------------------------------------------------------
| Internal API Routes
|--------------------------------------------------------------------------
| System-to-system and token-authenticated API endpoints live here.
| Laravel mounts this file under /api from bootstrap/app.php.
*/

/*
|--------------------------------------------------------------------------
| Laravel Route Helper
|--------------------------------------------------------------------------
| Route: registers endpoint paths, HTTP verbs, prefixes, and middleware.
*/
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Search Bridge and Vector Operations
|--------------------------------------------------------------------------
| HawkiRagProxyController: forwards search requests to the RAG bridge.
| RagStatsController: exposes vector/Qdrant stats and vector cleanup.
*/
use App\Http\Controllers\API\HawkiRagProxyController;
use App\Http\Controllers\API\OpenCompat\ApiKeyController as OpenCompatApiKeyController;
use App\Http\Controllers\API\OpenCompat\DocumentController as OpenCompatDocumentController;
use App\Http\Controllers\API\OpenCompat\FolderController as OpenCompatFolderController;
use App\Http\Controllers\API\OpenCompat\IngestController as OpenCompatIngestController;
use App\Http\Controllers\API\OpenCompat\ModelController as OpenCompatModelController;
use App\Http\Controllers\API\OpenCompat\RetrievalController as OpenCompatRetrievalController;
use App\Http\Controllers\API\OpenCompat\SystemController as OpenCompatSystemController;
use App\Http\Controllers\API\RagStatsController;
use App\Http\Controllers\SpecV2\ApplicationController as SpecApplicationController;
use App\Http\Controllers\SpecV2\AuthorizationController as SpecAuthorizationController;
use App\Http\Controllers\SpecV2\CorpusController as SpecCorpusController;
use App\Http\Controllers\SpecV2\GroupController as SpecGroupController;
use App\Http\Controllers\SpecV2\HeapController as SpecHeapController;
use App\Http\Controllers\SpecV2\TenantController as SpecTenantController;

/*
|--------------------------------------------------------------------------
| Graph API
|--------------------------------------------------------------------------
| RagGraphController: searches, expands, snapshots, and clears Neo4j graph data.
*/
use App\Http\Controllers\Graph\RagGraphController;

/*
|--------------------------------------------------------------------------
| Heap and Document API
|--------------------------------------------------------------------------
| DatasetController: compatibility layer for heap browser storage operations.
| DocumentBrowserController: lists and shows documents inside heaps.
*/
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DocumentBrowserController;

/*
|--------------------------------------------------------------------------
| Pipeline API
|--------------------------------------------------------------------------
| PipelineControlController: receives file uploads for ingestion.
| PipelineRecoveryController: retries failed jobs, tasks, and heaps.
| PipelineTaskController: manages task status, jobs, events, retries, cancel,
| and cache/history deletion.
*/
use App\Http\Controllers\PipelineControlController;
use App\Http\Controllers\PipelineRecoveryController;
use App\Http\Controllers\PipelineTaskController;

Route::middleware(['auth:application-token,sanctum,oidc', 'throttle:hawki-api'])->group(function () {
    Route::prefix('search')->group(function () {
        Route::post('/', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks', [OpenCompatRetrievalController::class, 'chunks'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks/grouped', [OpenCompatRetrievalController::class, 'groupedChunks'])->middleware('throttle:hawki-rag-query');
    });

    Route::prefix('retrieve')->group(function () {
        Route::post('/chunks', [OpenCompatRetrievalController::class, 'chunks'])->middleware('throttle:hawki-rag-query');
        Route::post('/chunks/grouped', [OpenCompatRetrievalController::class, 'groupedChunks'])->middleware('throttle:hawki-rag-query');
        Route::post('/docs', [OpenCompatRetrievalController::class, 'docs'])->middleware('throttle:hawki-rag-query');
    });
    Route::post('/batch/chunks', [OpenCompatRetrievalController::class, 'batchChunks'])->middleware('throttle:hawki-rag-query');
    Route::post('/search/documents', [OpenCompatRetrievalController::class, 'searchDocuments']);
    Route::post('/batch/documents', [OpenCompatRetrievalController::class, 'batchDocuments']);

    Route::post('/search', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
    Route::post('/query', [HawkiRagProxyController::class, 'query'])->middleware('throttle:hawki-rag-query');
});

Route::middleware(['auth:application-token,sanctum,oidc', 'throttle:hawki-api'])->group(function () {
    Route::prefix('ingest')->group(function () {
        Route::post('/text', [OpenCompatIngestController::class, 'text'])->middleware('throttle:hawki-rag-query');
        Route::post('/file', [OpenCompatIngestController::class, 'file'])->middleware('throttle:hawki-upload');
        Route::post('/files', [OpenCompatIngestController::class, 'files'])->middleware('throttle:hawki-upload');
        Route::post('/requeue', [OpenCompatIngestController::class, 'requeue'])->middleware('throttle:hawki-destructive');
        Route::post('/document/query', [OpenCompatIngestController::class, 'documentQuery'])->middleware('throttle:hawki-upload');
    });

    Route::prefix('datasets')->group(function () {
        Route::get('/', [DatasetController::class, 'index']);
        Route::post('/', [DatasetController::class, 'store']);
        Route::get('/{datasetId}', [DatasetController::class, 'show']);
        Route::delete('/{datasetId}/storage', [DatasetController::class, 'destroyStorage']);
    });

    Route::prefix('documents')->group(function () {
        Route::post('/', [OpenCompatDocumentController::class, 'list']);
        Route::post('/list_docs', [OpenCompatDocumentController::class, 'list']);
        Route::post('/pages', [OpenCompatDocumentController::class, 'pages']);
        Route::get('/filename/{filename}', [OpenCompatDocumentController::class, 'byFilename'])->where('filename', '.*');
        Route::get('/', [DocumentBrowserController::class, 'index']);
        Route::get('/{documentId}/status', [OpenCompatDocumentController::class, 'status']);
        Route::get('/{documentId}/summary', [OpenCompatDocumentController::class, 'summary']);
        Route::put('/{documentId}/summary', [OpenCompatDocumentController::class, 'summary']);
        Route::get('/{documentId}/download_url', [OpenCompatDocumentController::class, 'downloadUrl']);
        Route::get('/{documentId}/file', [OpenCompatDocumentController::class, 'file']);
        Route::post('/{documentId}/update_text', [OpenCompatDocumentController::class, 'updateText'])->middleware('throttle:hawki-upload');
        Route::post('/{documentId}/update_file', [OpenCompatDocumentController::class, 'updateFile'])->middleware('throttle:hawki-upload');
        Route::post('/{documentId}/update_metadata', [OpenCompatDocumentController::class, 'updateMetadata']);
        Route::delete('/{documentId}', [OpenCompatDocumentController::class, 'delete'])->middleware('throttle:hawki-destructive');
        Route::get('/{documentId}', [DocumentBrowserController::class, 'show']);
    });

    Route::prefix('folders')->group(function () {
        Route::post('/', [OpenCompatFolderController::class, 'create']);
        Route::get('/', [OpenCompatFolderController::class, 'list']);
        Route::post('/details', [OpenCompatFolderController::class, 'details']);
        Route::get('/summary', [OpenCompatFolderController::class, 'listSummaries']);
        Route::get('/{folderId}/summary', [OpenCompatFolderController::class, 'summary'])->where('folderId', '.*');
        Route::put('/{folderId}/summary', [OpenCompatFolderController::class, 'summary'])->where('folderId', '.*');
        Route::post('/{folderId}/documents/{documentId}', [OpenCompatFolderController::class, 'attachDocument'])->where('folderId', '.*');
        Route::delete('/{folderId}/documents/{documentId}', [OpenCompatFolderController::class, 'detachDocument'])->where('folderId', '.*');
        Route::post('/{folderId}/move', [OpenCompatFolderController::class, 'move'])->where('folderId', '.*');
        Route::delete('/{folderId}', [OpenCompatFolderController::class, 'delete'])->where('folderId', '.*')->middleware('throttle:hawki-destructive');
        Route::get('/{folderId}', [OpenCompatFolderController::class, 'show'])->where('folderId', '.*');
    });

    Route::get('/models', [OpenCompatModelController::class, 'list']);
    Route::get('/models/available', [OpenCompatModelController::class, 'list']);
    Route::post('/models', [OpenCompatModelController::class, 'unsupported']);
    Route::get('/models/custom', [OpenCompatModelController::class, 'unsupported']);
    Route::delete('/models/{modelId}', [OpenCompatModelController::class, 'unsupported'])->middleware('throttle:hawki-destructive');

    Route::prefix('tenants')->group(function () {
        Route::get('/', [SpecTenantController::class, 'index']);
        Route::post('/', [SpecTenantController::class, 'store']);
        Route::get('/{tenantId}', [SpecTenantController::class, 'show']);
    });

    Route::prefix('applications')->group(function () {
        Route::get('/', [SpecApplicationController::class, 'index']);
        Route::post('/', [SpecApplicationController::class, 'store']);
        Route::get('/{applicationId}', [SpecApplicationController::class, 'show']);
    });

    Route::prefix('heaps')->group(function () {
        Route::get('/', [SpecHeapController::class, 'index']);
        Route::post('/', [SpecHeapController::class, 'store']);
        Route::get('/{heapId}', [SpecHeapController::class, 'show']);
        Route::patch('/{heapId}', [SpecHeapController::class, 'update']);
        Route::delete('/{heapId}', [SpecHeapController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('corpora')->group(function () {
        Route::get('/', [SpecCorpusController::class, 'index']);
        Route::get('/{corpusId}', [SpecCorpusController::class, 'show']);
    });

    Route::prefix('groups')->group(function () {
        Route::get('/', [SpecGroupController::class, 'index']);
        Route::post('/', [SpecGroupController::class, 'store']);
        Route::get('/{groupId}/users', [SpecGroupController::class, 'users'])->where('groupId', '.*');
        Route::put('/{groupId}/users', [SpecGroupController::class, 'replaceUsers'])->where('groupId', '.*');
        Route::patch('/{groupId}/users', [SpecGroupController::class, 'updateUsers'])->where('groupId', '.*');
        Route::get('/{groupId}', [SpecGroupController::class, 'show'])->where('groupId', '.*');
        Route::delete('/{groupId}', [SpecGroupController::class, 'destroy'])->where('groupId', '.*')->middleware('throttle:hawki-destructive');
    });

    Route::prefix('auth')->group(function () {
        Route::get('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'heapGrants']);
        Route::put('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'replaceHeapGrants']);
        Route::patch('/heaps/{heapId}/grants', [SpecAuthorizationController::class, 'updateHeapGrants']);
        Route::get('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'documentGrants']);
        Route::put('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'replaceDocumentGrants']);
        Route::patch('/documents/{documentId}/grants', [SpecAuthorizationController::class, 'updateDocumentGrants']);
    });
});

Route::middleware(['auth:sanctum,oidc', 'throttle:hawki-api'])->group(function () {
    Route::get('/api-keys', [OpenCompatApiKeyController::class, 'list']);
    Route::post('/api-keys', [OpenCompatApiKeyController::class, 'save']);
    Route::post('/migrate/document', [OpenCompatSystemController::class, 'migrateDocument'])->middleware('throttle:hawki-upload');
    Route::get('/logs', [OpenCompatSystemController::class, 'logs']);
    Route::get('/usage/app-storage', [OpenCompatSystemController::class, 'usage']);

    Route::prefix('pipeline/tasks')->group(function () {
        Route::get('/', [PipelineTaskController::class, 'index']);
        Route::post('/start', [PipelineTaskController::class, 'start'])->middleware('throttle:hawki-upload');
        Route::get('/{taskId}', [PipelineTaskController::class, 'show']);
        Route::get('/{taskId}/jobs', [PipelineTaskController::class, 'jobs']);
        Route::get('/{taskId}/failed-jobs', [PipelineTaskController::class, 'failedJobs']);
        Route::get('/{taskId}/events', [PipelineTaskController::class, 'events']);
        Route::get('/{taskId}/stages/{stage}/logs', [PipelineTaskController::class, 'stageLogs']);
        Route::get('/{taskId}/stages/{stage}/logs/download', [PipelineTaskController::class, 'downloadStageLogs']);
        Route::post('/{taskId}/jobs', [PipelineTaskController::class, 'upsertJob']);
        Route::post('/{taskId}/retry', [PipelineTaskController::class, 'retry'])->middleware('throttle:hawki-destructive');
        Route::post('/{taskId}/retry-failed-jobs', [PipelineTaskController::class, 'retryFailedJobs'])->middleware('throttle:hawki-destructive');
        Route::post('/{taskId}/cancel', [PipelineTaskController::class, 'cancel'])->middleware('throttle:hawki-destructive');
        Route::delete('/{taskId}', [PipelineTaskController::class, 'destroy'])->middleware('throttle:hawki-destructive');
    });

    Route::prefix('pipeline/controller')->group(function () {
        Route::post('/files', [PipelineControlController::class, 'uploadFile'])->middleware('throttle:hawki-upload');
    });

    Route::prefix('pipeline/recovery')->group(function () {
        Route::get('/failed-jobs', [PipelineRecoveryController::class, 'failedJobs']);
        Route::post('/jobs/retry-selected', [PipelineRecoveryController::class, 'retrySelected'])->middleware('throttle:hawki-destructive');
        Route::post('/jobs/{jobId}/retry', [PipelineRecoveryController::class, 'retryJob'])->middleware('throttle:hawki-destructive');
        Route::post('/retry-all', [PipelineRecoveryController::class, 'retryAll'])->middleware('throttle:hawki-destructive');
        Route::post('/tasks/{taskId}/retry-failed', [PipelineRecoveryController::class, 'retryTask'])->middleware('throttle:hawki-destructive');
        Route::post('/heaps/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
        Route::post('/datasets/{datasetId}/retry-failed', [PipelineRecoveryController::class, 'retryDataset'])->middleware('throttle:hawki-destructive');
    });

    Route::get('/rag/stats', [RagStatsController::class, 'show']);
    Route::delete('/rag/qdrant/collections/{collection}', [RagStatsController::class, 'destroyQdrantCollection'])->middleware('throttle:hawki-destructive');
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
