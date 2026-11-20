<?php

declare(strict_types=1);
use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;

// Disposable browser fixture: real Laravel HTTP/auth/SQL; Temporal transport is faked.
$root = realpath((string) getenv('RAWKI_UI_DELETION_ROOT'));
if (! $root || ! str_starts_with(basename($root), 'rawki-ui-deletion-')) {
    throw new RuntimeException('An explicitly configured disposable UI test directory is required.');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/build/')) {
    $assets = realpath(__DIR__.'/../../storage/framework/testing/managed-ui-build');
    $asset = $assets ? realpath($assets.substr($path, strlen('/build'))) : false;
    if (! $asset || ! str_starts_with($asset, $assets.DIRECTORY_SEPARATOR) || ! is_file($asset)) {
        http_response_code(404);

        exit;
    }
    header('Content-Type: '.(str_ends_with($asset, '.css') ? 'text/css' : 'application/javascript'));
    readfile($asset);

    exit;
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/database.sqlite', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
Vite::useBuildDirectory('../storage/framework/testing/managed-ui-build')
    ->createAssetPathsUsing(fn ($asset) => '/build/'.substr($asset, strlen('../storage/framework/testing/managed-ui-build/')));
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $root.'/database.sqlite');
config()->set('database.connections.sqlite.url', null);
config()->set('temporal.enabled', true);
config()->set('temporal.callbacks.secret', 'ui-test-secret');
config()->set('temporal.storage.shared_root', $root);
config()->set('config.docker_project_path', '/');
config()->set('config.virtual_path', '/');
config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
Http::preventStrayRequests();
Http::fake(function ($request) use ($root) {
    if (str_ends_with($request->url(), '/temporal/workflows/delete-managed-document')) {
        $time = $request->header('X-Hawki-Timestamp')[0];
        $signature = 'v1='.hash_hmac('sha256', $time.'.'.$request->body(), 'ui-test-secret');
        if ($request->method() !== 'POST' || $request->header('X-Hawki-Signature')[0] !== $signature) {
            throw new RuntimeException('The managed deletion control signature is invalid.');
        }
        file_put_contents($root.'/dispatches.jsonl', $request->body()."\n", FILE_APPEND);
        if (file_get_contents($root.'/phase') === 'lost-ack') {
            return (Http::failedConnection())($request);
        }

        return Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'ui-delete-run', 'status' => 'pending']);
    }

    return Http::response(['ok' => true, 'result' => ['collections' => [], 'points_count' => 0]]);
});
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'seed') {
    touch($root.'/database.sqlite');
    Artisan::call('migrate', ['--force' => true]);
    $user = User::query()->create(['username' => 'ui-test', 'email' => 'ui-test@example.test', 'ip' => '127.0.0.1']);
    foreach (['owned', 'foreign'] as $scope) {
        PipelineTask::query()->create(['task_id' => 'task-'.$scope, 'dataset_id' => $scope, 'status' => 'completed']);
        $dataset = Dataset::query()->create(['dataset_id' => $scope, 'name' => $scope, 'status' => 'active', 'qdrant_collection' => $scope.'_current_collection', 'neo4j_namespace' => $scope.'_current_namespace']);
        if ($scope === 'owned') {
            $app->make(DatasetIngestionAuthorizationService::class)->grantAccess($user, $dataset);
        }
        ManagedDocument::query()->create(['document_id' => 'adoc_ui_'.$scope, 'dataset_id' => $scope, 'display_name' => $scope.'.pdf', 'source_type' => 'upload', 'status' => 'indexed', 'graph_enabled' => true, 'latest_source_id' => 'source-'.$scope, 'latest_task_id' => 'task-'.$scope]);
        IngestionSource::query()->create(['source_id' => 'source-'.$scope, 'dataset_id' => $scope, 'source_url' => 'upload://'.$scope.'.pdf', 'index_status' => 'ready', 'metadata' => [
            'request' => ['metadata' => ['managed_document_id' => 'adoc_ui_'.$scope]], 'managed_writer_protocol' => 1,
            'managed_writer_gate' => 'closed', 'managed_target_manifest_complete' => true,
        ]]);
        ManagedDocumentOutput::query()->create(['document_id' => 'adoc_ui_'.$scope, 'source_id' => 'source-'.$scope, 'bridge_document_id' => 'same-doc', 'qdrant_collection' => 'historical_collection', 'neo4j_namespace' => 'historical_namespace', 'active' => true, 'status' => 'indexed']);
    }
    echo json_encode(['token' => $user->createToken('ui-test', ['query'])->plainTextToken], JSON_THROW_ON_ERROR);

    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'state') {
    echo json_encode(['documents' => ManagedDocument::query()->orderBy('document_id')->get()->toArray(),
        'outputs' => ManagedDocumentOutput::query()->orderBy('document_id')->get()->toArray(),
        'sources' => IngestionSource::query()->orderBy('source_id')->get()->toArray(),
        'operations' => ManagedDocumentDeletion::query()->get()->toArray(), 'tasks' => PipelineTask::query()->get()->toArray()], JSON_THROW_ON_ERROR);

    exit;
}
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
