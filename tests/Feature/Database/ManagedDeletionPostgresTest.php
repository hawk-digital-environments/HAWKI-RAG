<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Tasks\IngestSourceWorkflowPayloadFactory;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Support\ManagedWriterFixture;
use Tests\TestCase;

final class ManagedDeletionPostgresTest extends TestCase
{
    use ManagedWriterFixture;

    private string $schema;

    private string $originalConnection;

    private array $connection;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MANAGED_POSTGRES_TESTS') !== '1' || getenv('RAWKI_MANAGED_TEST_DISPOSABLE') !== '1') {
            $this->markTestSkipped('Requires explicit disposable PostgreSQL configuration and RUN_MANAGED_POSTGRES_TESTS=1.');
        }
        $this->originalConnection = config('database.default');
        $this->schema = 'rawki_managed_delete_'.bin2hex(random_bytes(12));
        $database = $this->environment('RAWKI_MANAGED_TEST_POSTGRES_DATABASE');
        $this->assertStringContainsString('test', $database);
        $this->connection = ['driver' => 'pgsql', 'url' => null, 'host' => $this->environment('RAWKI_MANAGED_TEST_POSTGRES_HOST'),
            'port' => $this->environment('RAWKI_MANAGED_TEST_POSTGRES_PORT'), 'database' => $database,
            'username' => $this->environment('RAWKI_MANAGED_TEST_POSTGRES_USER'), 'password' => $this->environment('RAWKI_MANAGED_TEST_POSTGRES_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema, 'sslmode' => 'prefer'];
        $control = $this->independentConnection();
        $control->exec('CREATE SCHEMA '.$this->schema);
        config()->set('database.connections.managed_delete_pg', $this->connection);
        config()->set('database.default', 'managed_delete_pg');
        DB::purge('managed_delete_pg');
        $this->artisan('migrate', ['--database' => 'managed_delete_pg', '--force' => true])->assertExitCode(0);
        $this->storageRoot = storage_path('framework/testing/'.$this->schema);
        config()->set('temporal.storage.shared_root', $this->storageRoot);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        $this->seedWriter();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->schema, $this->connection)) {
            DB::disconnect('managed_delete_pg');
            $this->independentConnection()->exec('DROP SCHEMA '.$this->schema.' CASCADE');
            config()->set('database.default', $this->originalConnection);
            DB::purge('managed_delete_pg');
            File::deleteDirectory($this->storageRoot ?? storage_path('framework/testing/'.$this->schema));
        }
        parent::tearDown();
    }

    public function test_replacement_records_are_committed_and_immediate_callback_survives_ack_loss(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writer_protocol'] = 1;
        $metadata['managed_target_manifest_complete'] = true;
        $metadata['managed_writer_gate'] = 'closed';
        $source->update(['metadata' => $metadata]);
        $seenInput = null;
        Http::fake(function ($request) use (&$seenInput) {
            $payload = $request->data();
            if (str_ends_with($request->url(), '/delete-managed-document')) {
                return Http::response(['workflow_id' => $payload['workflow_id'], 'run_id' => 'cleanup-run', 'status' => 'completed', 'result' => ['input' => $payload['workflow_input'], 'results' => []]]);
            }
            if (str_ends_with($request->url(), '/workflows/ingest')) {
                $this->assertSame(0, DB::connection()->transactionLevel());
                $pdo = $this->independentConnection();
                $pdo->exec('SET search_path TO '.$this->schema);
                $input = $payload['workflow_input'];
                $seenInput = $input;
                foreach (['pipeline_tasks' => ['task_id', $input['task_id']], 'pipeline_jobs' => ['job_id', $input['job_id']], 'ingestion_sources' => ['source_id', $input['source_id']]] as $table => [$column, $value]) {
                    $query = $pdo->prepare('SELECT count(*) FROM '.$table.' WHERE '.$column.' = ?');
                    $query->execute([$value]);
                    $this->assertSame(1, (int) $query->fetchColumn());
                }
                $event = $this->writerEvent();
                $event['job_id'] = $input['job_id'];
                $event['source_id'] = $input['source_id'];
                $event['workflow_id'] = $payload['workflow_id'];
                $event['run_id'] = 'accepted-before-ack';
                $this->signedWriter($event)->assertOk();

                return (Http::failedConnection())($request);
            }

            return Http::response(['status' => 'RUNNING']);
        });
        $input = fn () => ['force' => true, 'file' => UploadedFile::fake()->createWithContent('new.pdf', 'durable bytes')];
        $this->put('/api/documents/adoc_writer_test', $input(), ['Accept' => 'application/json', 'Idempotency-Key' => 'committed-replacement'])->assertStatus(502);
        $job = PipelineJob::query()->where('job_id', $seenInput['job_id'])->firstOrFail();
        $this->assertSame('accepted-before-ack', $job->temporal_run_id);
        $replacement = IngestionSource::query()->where('source_id', $seenInput['source_id'])->firstOrFail();
        $this->assertCount(1, $replacement->metadata['managed_writers']);
        $this->put('/api/documents/adoc_writer_test', $input(), ['Accept' => 'application/json', 'Idempotency-Key' => 'committed-replacement'])->assertAccepted();
        $this->assertSame($seenInput, $replacement->fresh()->metadata['managed_dispatch']['input']);
        $this->assertSame(2, PipelineJob::query()->count());
    }

    #[TestWith(['/api/documents'])]
    #[TestWith(['/api/pipeline/controller/files'])]
    public function test_initial_creation_commits_owner_and_all_execution_records_before_immediate_registration(string $endpoint): void
    {
        $registered = false;
        Http::fake(function ($request) use (&$registered) {
            if (! str_ends_with($request->url(), '/workflows/ingest')) {
                return Http::response(['status' => 'RUNNING']);
            }
            $this->assertSame(0, DB::connection()->transactionLevel());
            $input = $request->data()['workflow_input'];
            $pdo = $this->independentConnectionWithSchema();
            foreach (['managed_documents' => ['document_id', $input['managed_document_id']], 'pipeline_tasks' => ['task_id', $input['task_id']],
                'pipeline_jobs' => ['job_id', $input['job_id']], 'ingestion_sources' => ['source_id', $input['source_id']]] as $table => [$column, $id]) {
                $query = $pdo->prepare('SELECT count(*) FROM '.$table.' WHERE '.$column.' = ?');
                $query->execute([$id]);
                $this->assertSame(1, (int) $query->fetchColumn());
            }
            $event = array_merge($this->writerEvent(), ['source_id' => $input['source_id'], 'job_id' => $input['job_id'], 'workflow_id' => $request->data()['workflow_id'], 'run_id' => 'initial-fast-run']);
            $this->assertSame(200, $this->concurrentWriter($event));
            $registered = true;

            return Http::response(['workflow_id' => $event['workflow_id'], 'run_id' => $event['run_id']]);
        });
        $response = $this->post($endpoint, ['dataset_id' => 'writer-test', 'file' => UploadedFile::fake()->createWithContent('new.pdf', 'durable bytes')], ['Accept' => 'application/json']);
        $this->assertContains($response->getStatusCode(), [201, 202], $response->getContent());
        $this->assertTrue($registered);
        $source = IngestionSource::query()->where('source_id', '!=', 'source-writer')->firstOrFail();
        $this->assertCount(1, $source->metadata['managed_writers']);
    }

    public function test_process_crash_after_initial_preparation_can_redispatch_without_original_upload(): void
    {
        File::ensureDirectoryExists($this->storageRoot);
        $process = $this->managedRequestProcess('crash', '/api/documents');
        $process->run();
        $this->assertSame(86, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $taskId = File::get($this->storageRoot.'/prepared');
        $source = IngestionSource::query()->where('task_id', $taskId)->firstOrFail();
        $owner = $source->metadata['managed_dispatch']['input']['managed_document_id'];
        $this->assertDatabaseHas('managed_documents', ['document_id' => $owner, 'latest_source_id' => $source->source_id]);
        $this->assertFalse($source->metadata['managed_dispatch']['attempted']);
        $frozen = $source->metadata['managed_dispatch']['input'];
        Http::fake(function ($request) use ($frozen) {
            if (str_ends_with($request->url(), '/workflows/ingest')) {
                $this->assertSame(0, DB::connection()->transactionLevel());
                $this->assertSame($frozen, $request->data()['workflow_input']);

                return Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'redispatched-initial-run']);
            }

            return Http::response(['status' => 'RUNNING']);
        });
        $result = app(PipelineUploadService::class)->dispatchPreparedManagedUpload($taskId);
        $this->assertTrue($result->payload['success']);
        $this->assertSame('redispatched-initial-run', PipelineJob::query()->where('task_id', $taskId)->firstOrFail()->temporal_run_id);
        $this->assertSame($frozen, $source->fresh()->metadata['managed_dispatch']['input']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_dataset_archival_serializes_with_new_source_reservation(bool $archiveFirst): void
    {
        ManagedDocument::query()->delete();
        PipelineJob::query()->delete();
        IngestionSource::query()->delete();
        PipelineTask::query()->delete();
        File::ensureDirectoryExists($this->storageRoot);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config()->set('database.connections.managed_delete_child', $input['connection']);
config()->set('database.default', 'managed_delete_child');
Illuminate\Support\Facades\Http::preventStrayRequests();
file_put_contents($input['marker'], 'ready');
if ($input['archive_first']) {
    try {
        app(App\Services\Pipeline\Repositories\IngestionSourceRepository::class)->upsertStarting('late-source', ['dataset_id' => 'writer-test', 'source_url' => 'upload://late.pdf']);
        exit(1);
    } catch (RuntimeException $error) {
        echo $error->getMessage();
    }
} else {
    echo app(App\Services\Dataset\DatasetService::class)->delete('writer-test')['status'];
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $process->setInput(json_encode(['connection' => $this->connection, 'archive_first' => $archiveFirst,
            'marker' => $this->storageRoot.'/reservation-ready'], JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        DB::beginTransaction();
        try {
            if ($archiveFirst) {
                Dataset::query()->where('dataset_id', 'writer-test')->update(['status' => 'archived']);
            } else {
                app(IngestionSourceRepository::class)->upsertStarting('late-source', ['dataset_id' => 'writer-test', 'source_url' => 'upload://late.pdf']);
            }
            $process->start();
            $deadline = microtime(true) + 10;
            while (! File::exists($this->storageRoot.'/reservation-ready') && microtime(true) < $deadline) {
                usleep(100_000);
            }
            $this->assertFileExists($this->storageRoot.'/reservation-ready');
            usleep(100_000);
            $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame($archiveFirst ? 'Dataset is unavailable for new ingestion.' : '409', $process->getOutput());
            $this->assertSame($archiveFirst ? 0 : 1, IngestionSource::query()->where('source_id', 'late-source')->count());
            $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'archived']);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    public function test_deletion_fences_committed_creation_before_delayed_dispatch(): void
    {
        $process = $this->managedRequestProcess('crash', '/api/documents');
        $process->run();
        $this->assertSame(86, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $taskId = File::get($this->storageRoot.'/prepared');
        $source = IngestionSource::query()->where('task_id', $taskId)->firstOrFail();
        $input = $source->metadata['managed_dispatch']['input'];
        $this->assertFalse($source->metadata['managed_dispatch']['attempted']);
        Http::fake(fn ($request) => Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'pending-delete-run', 'status' => 'pending']));

        $this->deleteJson('/api/documents/'.$input['managed_document_id'])->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertSame([], $operation->workflow_input['targets']);
        $event = array_merge($this->writerEvent(), ['source_id' => $source->source_id, 'job_id' => $input['job_id'],
            'workflow_id' => $source->temporal_workflow_id, 'run_id' => 'delayed-initial-run']);
        $this->assertSame(409, $this->concurrentWriter($event));
        $this->assertSame('deleting', $source->fresh()->metadata['managed_deletion']['status']);
        try {
            app(PipelineUploadService::class)->dispatchPreparedManagedUpload($taskId);
            $this->fail('Delayed dispatch must be fenced before Temporal startup.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Managed upload was fenced before dispatch.', $error->getMessage());
        }
        Http::assertSentCount(1);

        $this->assertSame(200, $this->concurrentWriter(['schema_version' => 1, 'event_id' => 'pending-creation-delete',
            'operation_id' => $operation->operation_id, 'workflow_id' => $operation->workflow_id,
            'run_id' => 'pending-delete-run', 'status' => 'completed', 'results' => []], '/api/internal/pipeline/managed-deletion-events'));
        $this->assertSame('deleted', ManagedDocument::query()->findOrFail($input['managed_document_id'])->status);
        $this->assertSame(409, $this->concurrentWriter($event));
        $this->assertDatabaseCount('managed_document_outputs', 0);
    }

    public function test_duplicate_put_continuation_cannot_resurrect_document_after_completed_delete(): void
    {
        $this->signedWriter($this->writerEvent('acquire'))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $cleanupPending = true;
        Http::fake(function ($request) use (&$cleanupPending) {
            $payload = $request->data();
            if (str_ends_with($request->url(), '/delete-managed-document')) {
                return Http::response(['workflow_id' => $payload['workflow_id'], 'run_id' => 'cleanup-run', 'status' => $cleanupPending ? 'pending' : 'completed',
                    'result' => ['input' => $payload['workflow_input'], 'results' => []]]);
            }
            if (str_ends_with($request->url(), '/workflows/ingest')) {
                $this->assertSame(0, DB::connection()->transactionLevel());
                $input = $payload['workflow_input'];
                $event = array_merge($this->writerEvent(), ['source_id' => $input['source_id'], 'job_id' => $input['job_id'], 'workflow_id' => $payload['workflow_id'], 'run_id' => 'replacement-race-run']);
                $this->assertSame(200, $this->concurrentWriter($event));
                $this->assertSame(200, $this->concurrentWriter([...$event, 'action' => 'release']));
                IngestionSource::query()->where('source_id', $input['source_id'])->update(['index_status' => 'ready']);

                return Http::response(['workflow_id' => $payload['workflow_id'], 'run_id' => 'replacement-race-run']);
            }

            return Http::response(['status' => 'COMPLETED']);
        });
        $put = fn () => $this->put('/api/documents/adoc_writer_test', ['force' => true, 'file' => UploadedFile::fake()->createWithContent('new.pdf', 'durable bytes')],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'duplicate-publication']);
        $put()->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertSame(200, $this->concurrentWriter(['schema_version' => 1, 'event_id' => 'old-cleanup', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'cleanup-run', 'status' => 'completed', 'results' => []], '/api/internal/pipeline/managed-deletion-events'));
        $process = $this->managedRequestProcess('pause', '/api/documents/adoc_writer_test');
        try {
            $process->start();
            $paused = false;
            for ($poll = 0; $poll < 100; $poll++) {
                if (is_file($this->storageRoot.'/paused')) {
                    $paused = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($paused, $process->getErrorOutput().$process->getOutput());
            $put()->assertAccepted();
            $this->assertSame('continued', $operation->fresh()->status);
            $cleanupPending = false;
            $this->deleteJson('/api/documents/adoc_writer_test', [], ['Idempotency-Key' => 'intervening-delete'])->assertOk();
            $tombstone = ManagedDocument::query()->firstOrFail()->deleted_at->toJSON();
            File::put($this->storageRoot.'/resume', '1');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('202', $process->getOutput());
            $document = ManagedDocument::query()->firstOrFail();
            $this->assertSame('deleted', $document->status);
            $this->assertSame($tombstone, $document->deleted_at->toJSON());
            $this->assertSame('continued', $operation->fresh()->status);
        } finally {
            File::put($this->storageRoot.'/resume', '1');
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    private function managedRequestProcess(string $mode, string $path): Process
    {
        File::ensureDirectoryExists($this->storageRoot);
        File::put($this->storageRoot.'/request.pdf', 'durable bytes');
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config()->set('database.connections.managed_delete_child', $input['connection']);
config()->set('database.default', 'managed_delete_child');
config()->set('temporal.storage.shared_root', $input['root']);
config()->set('temporal.callbacks.secret', 'writer-test-secret');
config()->set('file_converter.raganything_supported_extensions', ['pdf']);
Laravel\Sanctum\Sanctum::actingAs(App\Models\User::query()->findOrFail($input['user_id']));
Illuminate\Support\Facades\Http::preventStrayRequests();
if ($input['mode'] === 'crash') {
    $original = $app->make(App\Services\Pipeline\Uploads\PipelineUploadService::class);
    $proxy = Mockery::mock(App\Services\Pipeline\Uploads\PipelineUploadService::class);
    $proxy->shouldReceive('upload')->andReturnUsing(fn (...$args) => $original->upload(...$args));
    $proxy->shouldReceive('dispatchPreparedManagedUpload')->andReturnUsing(function ($taskId) use ($input) {
        if (Illuminate\Support\Facades\DB::connection()->transactionLevel() !== 0) { exit(87); }
        file_put_contents($input['root'].'/prepared', $taskId);
        exit(86);
    });
    $app->instance(App\Services\Pipeline\Uploads\PipelineUploadService::class, $proxy);
} else {
    Illuminate\Support\Facades\Http::fake(function ($request) use ($input) {
        if (str_ends_with($request->url(), '/workflows/ingest')) {
            if (Illuminate\Support\Facades\DB::connection()->transactionLevel() !== 0) { exit(87); }
            file_put_contents($input['root'].'/paused', '1');
            for ($poll = 0; $poll < 150 && ! is_file($input['root'].'/resume'); $poll++) { usleep(100000); }
            if (! is_file($input['root'].'/resume')) { throw new RuntimeException('Publication race timed out'); }
            return Illuminate\Support\Facades\Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'replacement-race-run']);
        }
        return Illuminate\Support\Facades\Http::response(['status' => 'COMPLETED']);
    });
}
$parameters = $input['mode'] === 'crash' ? ['dataset_id' => 'writer-test'] : ['force' => 'true'];
$request = Illuminate\Http\Request::create($input['path'], $input['mode'] === 'crash' ? 'POST' : 'PUT', $parameters, [],
    ['file' => new Illuminate\Http\UploadedFile($input['root'].'/request.pdf', 'new.pdf', 'application/pdf', 0, true)],
    ['HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'duplicate-publication']);
$response = $kernel->handle($request);
echo $response->getStatusCode();
if ($response->getStatusCode() >= 400) { fwrite(STDERR, $response->getContent()); }
$kernel->terminate($request, $response);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $process->setInput(json_encode(['connection' => $this->connection, 'root' => $this->storageRoot, 'user_id' => User::query()->firstOrFail()->id, 'mode' => $mode, 'path' => $path], JSON_THROW_ON_ERROR));
        $process->setTimeout(25);

        return $process;
    }

    public function test_preparation_rolls_back_without_starting_temporal(): void
    {
        Http::fake();
        $input = PipelineUploadInput::fromValidated(['dataset_id' => 'writer-test', 'graph' => 'true', 'request_metadata' => ['managed_document_id' => 'adoc_writer_test']]);
        try {
            DB::transaction(function () use ($input): void {
                app(PipelineUploadService::class)->upload($input, UploadedFile::fake()->createWithContent('prepared.pdf', 'bytes'), 'task_managed_rollback', true);
                $this->assertSame(0, (int) $this->independentConnectionWithSchema()->query("SELECT count(*) FROM pipeline_tasks WHERE task_id='task_managed_rollback'")->fetchColumn());
                throw new \RuntimeException('Injected preparation crash');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected preparation crash', $error->getMessage());
        }
        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => 'task_managed_rollback']);
        Http::assertNothingSent();
    }

    public function test_committed_preparation_survives_crash_before_external_dispatch(): void
    {
        Http::fake();
        $input = PipelineUploadInput::fromValidated(['dataset_id' => 'writer-test', 'graph' => 'true', 'request_metadata' => ['managed_document_id' => 'adoc_writer_test']]);
        DB::transaction(fn () => app(PipelineUploadService::class)->upload($input, UploadedFile::fake()->createWithContent('prepared.pdf', 'bytes'), 'task_managed_committed', true));
        $this->assertSame(1, (int) $this->independentConnectionWithSchema()->query("SELECT count(*) FROM pipeline_tasks WHERE task_id='task_managed_committed'")->fetchColumn());
        Http::assertNothingSent();
        $source = IngestionSource::query()->where('task_id', 'task_managed_committed')->firstOrFail();
        $this->assertFalse($source->metadata['managed_dispatch']['attempted']);
    }

    #[TestWith(['task', 'before_commit'])]
    #[TestWith(['recovery', 'before_commit'])]
    #[TestWith(['task', 'after_commit'])]
    #[TestWith(['recovery', 'after_commit'])]
    public function test_recovery_crash_commits_running_source_and_dispatch_identity_atomically(string $entrypoint, string $crashPoint): void
    {
        $this->signedWriter($this->writerEvent('acquire'))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $path = $this->storageRoot.'/writer-task/original.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'durable original bytes');
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['upload'] = ['original_filename' => 'original.pdf', 'local_path' => $path, 'extension' => 'pdf'];
        $source->update(['metadata' => $metadata, 'content_hash' => hash_file('sha256', $path)]);
        $original = app(IngestSourceWorkflowPayloadFactory::class)->input(PipelineTask::query()->firstOrFail(), PipelineJob::query()->firstOrFail(), $source);
        $original['ingestion'] = $metadata['managed_dispatch']['input']['ingestion'];
        $metadata['managed_dispatch']['input'] = $original;
        $source->update(['metadata' => $metadata]);

        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config()->set('database.connections.managed_delete_child', $input['connection']);
config()->set('database.default', 'managed_delete_child');
config()->set('temporal.storage.shared_root', $input['root']);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) {
    if (! str_ends_with($request->url(), '/workflows/status')) { exit(88); }
    return Illuminate\Support\Facades\Http::response(['status' => 'FAILED']);
});
if ($input['crash_point'] === 'before_commit') {
    Illuminate\Support\Facades\DB::listen(function ($query) {
        if (str_contains($query->sql, 'update "pipeline_jobs"') && str_contains($query->sql, '"temporal_workflow_id"')) {
            exit(85); // Die after reservation writes, before they can commit.
        }
    });
} else {
    Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionCommitted::class, function () {
        if (Illuminate\Support\Facades\DB::connection()->transactionLevel() === 0
            && App\Models\IngestionSource::query()->where('source_id', 'source-writer')->value('index_status') === 'running') {
            exit(86); // Die at the first durable running transition, before external startup.
        }
    });
}
if ($input['entrypoint'] === 'task') {
    $app->make(App\Services\Pipeline\Tasks\PipelineTaskRetryService::class)->retryFailedJobs('writer-task');
} else {
    $app->make(App\Services\Pipeline\Recovery\PipelineRecoveryAttemptService::class)->retry(App\Models\PipelineJob::query()->firstOrFail(), 'job', 'writer-job');
}
exit(89);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $process->setInput(json_encode(['connection' => $this->connection, 'root' => $this->storageRoot, 'entrypoint' => $entrypoint, 'crash_point' => $crashPoint], JSON_THROW_ON_ERROR));
        $process->setTimeout(10);
        $process->run();
        $this->assertSame($crashPoint === 'before_commit' ? 85 : 86, $process->getExitCode(), $process->getErrorOutput());
        $source = $source->fresh();
        $job = PipelineJob::query()->firstOrFail();
        if ($crashPoint === 'before_commit') {
            $this->assertSame('failed', $source->index_status);
            $this->assertSame('writer-workflow', $source->temporal_workflow_id);
            $this->assertSame('writer-workflow', $job->temporal_workflow_id);
            $this->assertSame('writer-run', $job->temporal_run_id);
            $this->assertSame($original, $source->metadata['managed_dispatch']['input']);

            return;
        }
        $this->assertSame('running', $source->index_status);
        $this->assertNotSame('writer-workflow', $source->temporal_workflow_id);
        $this->assertSame($source->temporal_workflow_id, $job->temporal_workflow_id);
        $this->assertNull($job->temporal_run_id);
        $this->assertSame($original['ingestion'], $source->metadata['managed_dispatch']['input']['ingestion']);
        $this->assertContains(['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run'], $source->metadata['managed_writer_history']);
        $frozen = $source->metadata['managed_dispatch']['input'];
        Http::fake(function ($request) use ($job, $frozen) {
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertSame($frozen, $request->data()['workflow_input']);
            $event = array_merge($this->writerEvent(), ['workflow_id' => $job->temporal_workflow_id, 'run_id' => 'resumed-recovery-run']);
            $this->assertSame(200, $this->concurrentWriter($event));

            return Http::response(['workflow_id' => $event['workflow_id'], 'run_id' => $event['run_id']]);
        });
        $result = app(PipelineUploadService::class)->dispatchPreparedManagedUpload('writer-task');
        $this->assertTrue($result->payload['success'], json_encode($result->payload, JSON_THROW_ON_ERROR));
        $this->assertSame('resumed-recovery-run', $job->fresh()->temporal_run_id);
        $this->assertSame($frozen, $source->fresh()->metadata['managed_dispatch']['input']);
    }

    public function test_pending_cleanup_dispatch_waits_for_outer_release_commit(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        Http::assertNothingSent();
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $query = $this->independentConnectionWithSchema()->query("SELECT metadata FROM ingestion_sources WHERE source_id = 'source-writer'");
            $metadata = json_decode($query->fetchColumn(), true);
            $this->assertSame('released', array_values($metadata['managed_writers'])[0]['status']);

            return Http::response(['workflow_id' => $request['workflow_id'], 'run_id' => 'delete-run', 'status' => 'pending']);
        });
        DB::beginTransaction();
        try {
            $this->signedWriter($this->writerEvent('release'))->assertOk();
            Http::assertNothingSent();
            DB::commit();
            Http::assertSentCount(1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_writer_release_does_not_wait_for_document_lock(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        DB::beginTransaction();
        ManagedDocument::query()->whereKey('adoc_writer_test')->lockForUpdate()->firstOrFail();
        $status = $this->concurrentWriter($this->writerEvent('release'));
        $this->assertSame(200, $status);
        DB::commit();
        $this->assertSame('released', array_values(IngestionSource::query()->firstOrFail()->metadata['managed_writers'])[0]['status']);
    }

    public function test_failed_callback_in_another_process_precedes_pending_acknowledgement(): void
    {
        $this->signedWriter($this->writerEvent('acquire'))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        Http::fake(function ($request) {
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $event = ['schema_version' => 1, 'event_id' => 'failed-before-ack', 'operation_id' => $operation->operation_id,
                'workflow_id' => $operation->workflow_id, 'run_id' => 'callback-run', 'status' => 'failed', 'results' => []];
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertSame(200, $this->concurrentWriter($event, '/api/internal/pipeline/managed-deletion-events'));

            return Http::response(['workflow_id' => $operation->workflow_id, 'run_id' => 'callback-run', 'status' => 'pending']);
        });
        $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(502);
        $this->assertSame('failed', ManagedDocumentDeletion::query()->firstOrFail()->status);
    }

    public function test_additive_recovery_migration_preserves_existing_operation(): void
    {
        Http::fake(fn ($request) => Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'cleanup-run', 'status' => 'pending']));
        $this->signedWriter($this->writerEvent('acquire'))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $operationId = ManagedDocumentDeletion::query()->firstOrFail()->operation_id;
        $migration = require database_path('migrations/2026_10_12_000000_add_managed_deletion_recovery_state.php');
        $migration->down();
        $this->assertSame($operationId, DB::table('managed_document_deletions')->value('operation_id'));
        $migration->up();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertSame($operationId, $operation->operation_id);
        $this->assertNull($operation->attempts);
        $this->assertNull($operation->replacement);
    }

    public function test_writer_waiting_on_deletion_snapshot_is_rejected_after_commit(): void
    {
        $this->signedWriter($this->writerEvent('acquire'))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        DB::beginTransaction();
        try {
            app(ManagedDocumentOutputDeletionService::class)->prepare(ManagedDocument::query()->firstOrFail(), 'delete', 'fence-race');
            $event = $this->writerEvent();
            $event['attempt'] = 2;
            $process = $this->callbackProcess($event, '/api/internal/pipeline/managed-writers');
            $process->start();
            $pdo = $this->independentConnection();
            $query = $pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
            $blocked = false;
            for ($poll = 0; $poll < 30; $poll++) {
                $query->execute(['child_'.$this->schema]);
                if ((int) $query->fetchColumn() === 1) {
                    $blocked = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($blocked, 'The independent writer did not reach the source lock.');
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('409', $process->getOutput());
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            if (isset($process) && $process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertCount(1, IngestionSource::query()->firstOrFail()->metadata['managed_writers']);
    }

    public function test_acquisition_waiting_for_document_does_not_block_existing_writer_release(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        DB::beginTransaction();
        try {
            ManagedDocument::query()->whereKey('adoc_writer_test')->lockForUpdate()->firstOrFail();
            $event = $this->writerEvent('acquire', [$this->target('two.md')]);
            $event['attempt'] = 2;
            $process = $this->callbackProcess($event, '/api/internal/pipeline/managed-writers');
            $process->start();
            $query = $this->independentConnection()->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
            $blocked = false;
            for ($poll = 0; $poll < 30; $poll++) {
                $query->execute(['child_'.$this->schema]);
                if ((int) $query->fetchColumn() === 1) {
                    $blocked = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($blocked, 'Acquisition did not wait on the document lock.');
            $this->signedWriter($this->writerEvent('release'))->assertOk();
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('200', $process->getOutput());
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            if (isset($process) && $process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertDatabaseCount('managed_document_outputs', 2);
    }

    public function test_hidden_task_flag_retains_evidence_and_survives_a_stale_recovery_update(): void
    {
        $task = PipelineTask::query()->firstOrFail();
        $repository = app(PipelineTaskRepository::class);
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->deleteJson('/api/pipeline/tasks/writer-task')->assertOk()->assertJsonPath('history_hidden', true);
        $this->assertTrue($task->fresh()->metadata['history_hidden']);
        $this->assertCount(0, $repository->recent());
        $repository->markRecoveryRunning($task, ['retry_count' => 1]);
        $this->assertCount(0, $repository->recent());
        $this->assertTrue($task->fresh()->metadata['history_hidden']);
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'writer-job']);
        $this->assertDatabaseHas('ingestion_sources', ['source_id' => 'source-writer']);
        $this->assertSame(200, $this->concurrentWriter($this->writerEvent('release')));
    }

    private function concurrentWriter(array $event, string $path = '/api/internal/pipeline/managed-writers'): int
    {
        $process = $this->callbackProcess($event, $path);
        $process->mustRun();

        return (int) $process->getOutput();
    }

    private function callbackProcess(array $event, string $path): Process
    {
        // A fresh PHP process avoids inheriting a parent's PDO socket and transaction.
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config()->set('database.connections.managed_delete_child', $input['connection']);
config()->set('database.default', 'managed_delete_child');
config()->set('temporal.callbacks.secret', 'writer-test-secret');
Illuminate\Support\Facades\DB::statement("SET application_name TO 'child_".$input['connection']['search_path']."'");
$body = json_encode($input['event'], JSON_THROW_ON_ERROR);
$time = (string) time();
$request = Illuminate\Http\Request::create($input['path'], 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_HAWKI_TIMESTAMP' => $time,
    'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $time.'.'.$body, 'writer-test-secret'),
], $body);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $process->setInput(json_encode(['connection' => $this->connection, 'event' => $event, 'path' => $path], JSON_THROW_ON_ERROR));
        $process->setTimeout(5);

        return $process;
    }

    private function independentConnection(): \PDO
    {
        return new \PDO('pgsql:host='.$this->connection['host'].';port='.$this->connection['port'].';dbname='.$this->connection['database'],
            $this->connection['username'], $this->connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function independentConnectionWithSchema(): \PDO
    {
        $pdo = $this->independentConnection();
        $pdo->exec('SET search_path TO '.$this->schema);

        return $pdo;
    }

    private function environment(string $key): string
    {
        $value = getenv($key);
        if (! is_string($value) || $value === '') {
            self::fail('Explicit disposable configuration is missing: '.$key);
        }

        return $value;
    }
}
