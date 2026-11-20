<?php

declare(strict_types=1);

namespace Tests\Feature\Datasets;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Dataset\DatasetService;
use App\Services\Dataset\Exceptions\DatasetInactiveException;
use App\Services\Pipeline\Tasks\PipelineTaskRetryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ManagedWriterFixture;
use Tests\TestCase;

final class DatasetDeletionBackgroundTest extends TestCase
{
    use DatabaseMigrations, ManagedWriterFixture;

    private bool $failStartup = false;

    private bool $cleanupFinished = false;

    private array $dispatches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWriter();
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/workflows/cancel')) {
                return Http::response([]);
            }
            if (str_ends_with($request->url(), '/delete-managed-document')) {
                $this->assertSame(0, DB::transactionLevel());
                $this->dispatches[] = $request['workflow_id'];
                if ($this->failStartup) {
                    return (Http::failedConnection())($request);
                }
                $input = $request['workflow_input'];
                foreach ($input['targets'] as $target) {
                    $this->assertSame('historic_collection', $target['collection']);
                    $this->assertSame('historic_namespace', $target['neo4j_namespace']);
                }

                return Http::response(['workflow_id' => $request['workflow_id'], 'run_id' => 'cleanup-run',
                    'status' => $this->cleanupFinished ? 'completed' : 'pending',
                    'result' => ['input' => $input, 'results' => array_map(fn ($target) => [
                        'output_id' => $target['output_id'],
                        'qdrant' => ['verified' => true, 'remaining_points' => 0, 'collection' => $target['collection'],
                            'dataset_id' => $input['dataset_id'], 'source_id' => $target['source_id'], 'doc_id' => $target['doc_id']],
                        'neo4j' => ['verified' => true, 'required' => true, 'remaining_contributions' => 0,
                            'namespace' => $target['neo4j_namespace'], 'dataset_id' => $input['dataset_id'], 'doc_id' => $target['doc_id']],
                    ], $input['targets'])]]);
            }
            if (str_starts_with($request->url(), 'http://qdrant.test')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame(['must' => [['key' => 'dataset_id', 'match' => ['value' => 'writer-test']]]], $request['filter']);

                return Http::response(['result' => ['count' => 0]]);
            }
            if (str_starts_with($request->url(), 'http://neo4j.test')) {
                foreach ($request['statements'] as $statement) {
                    $this->assertStringNotContainsString('DELETE', $statement['statement']);
                    $this->assertStringNotContainsString(' OR ', $statement['statement']);
                    $this->assertSame(['dataset_id' => 'writer-test', 'namespace' => 'current_namespace'], $statement['parameters']);
                }

                return Http::response(['results' => [['data' => [['row' => [0]]]], ['data' => [['row' => [0]]]]], 'errors' => []]);
            }

            return null;
        });
    }

    public function test_delete_immediately_hides_dataset_without_contacting_workers_or_sinks(): void
    {
        $user = User::query()->firstOrFail();
        $queries = app(DatasetQueryAuthorizationService::class);
        $queries->grantQueryAccess($user, Dataset::query()->where('dataset_id', 'writer-test')->firstOrFail());
        $this->assertTrue($queries->canQuery($user, 'writer-test'));
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->deleteJson('/api/datasets/writer-test/storage')->assertAccepted()
            ->assertJsonPath('success', true)->assertJsonPath('cleanup.cleanup_pending', true);
        Http::assertNothingSent();
        $this->assertDatabaseCount('managed_document_deletions', 0);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'archived']);
        $this->getJson('/api/datasets/writer-test')->assertNotFound();
        $this->getJson('/api/datasets')->assertOk()->assertJsonMissing(['dataset_id' => 'writer-test']);
        $this->assertFalse($queries->canQuery($user, 'writer-test'));
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertStatus(409);
        $this->postJson('/api/pipeline/tasks/start', ['dataset_id' => 'writer-test', 'urls' => ['https://example.test/late']])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_scheduler_waits_for_drain_then_finishes_without_another_delete_request(): void
    {
        Dataset::query()->create(['dataset_id' => 'control', 'name' => 'Control', 'status' => 'active',
            'qdrant_collection' => 'control_collection', 'neo4j_namespace' => 'control_namespace']);
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'running']);
        PipelineJob::query()->firstOrFail()->update(['status' => 'running']);
        $this->deleteJson('/api/datasets/writer-test/storage')->assertAccepted();
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseHas('managed_document_deletions', ['status' => 'pending']);
        $this->assertSame([], $this->dispatches);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertSame([], $this->dispatches);
        $this->assertSame('active', array_values(IngestionSource::query()->firstOrFail()->metadata['managed_writers'])[0]['status']);

        // A late admitted mutation has returned; only now may the writer release.
        $this->cleanupFinished = true;
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->assertDatabaseHas('managed_documents', ['document_id' => 'adoc_writer_test', 'status' => 'deleted']);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'deleted']);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'control', 'status' => 'active']);
        $this->assertDatabaseHas('ingestion_sources', ['source_id' => 'source-writer', 'index_status' => 'deleted']);
        $this->assertSame('completed', ManagedDocumentDeletion::query()->firstOrFail()->status);
        $this->signedWriter([...$this->writerEvent('acquire', [$this->target()]), 'attempt' => 2])->assertStatus(409);
        $this->deleteJson('/api/datasets/writer-test/storage')->assertOk()->assertJsonPath('cleanup.cleanup_pending', false);
    }

    public function test_failed_dispatch_is_recovered_from_database_on_next_scheduler_scan(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/datasets/writer-test/storage')->assertAccepted();
        $this->failStartup = true;
        $this->artisan('schedule:run')->assertExitCode(0);
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertNotNull($operation->last_error);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'archived']);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);

        $this->failStartup = false;
        $this->cleanupFinished = true;
        app()->forgetInstance(DatasetService::class);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseCount('managed_document_deletions', 1);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertGreaterThanOrEqual(2, count($this->dispatches));
        $this->assertSame([$operation->workflow_id], array_values(array_unique($this->dispatches)));
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'deleted']);
        $this->expectException(DatasetInactiveException::class);
        app(DatasetService::class)->ensure('writer-test');
    }

    public function test_archived_dataset_cannot_retry_before_background_cleanup_starts(): void
    {
        $this->deleteJson('/api/datasets/writer-test/storage')->assertAccepted();
        $this->assertDatabaseCount('managed_document_deletions', 0);
        $this->expectException(DatasetInactiveException::class);
        app(PipelineTaskRetryService::class)->retryFailedJobs('writer-task');
    }

    public function test_ambiguous_writer_is_preserved_without_blocking_other_archived_datasets(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('uncertain'))->assertOk();
        $this->deleteJson('/api/datasets/writer-test/storage')->assertAccepted();
        Dataset::query()->create(['dataset_id' => 'other-archived', 'name' => 'Other', 'status' => 'archived',
            'qdrant_collection' => '', 'neo4j_namespace' => '']);
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'other-archived', 'status' => 'deleted']);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'writer-test', 'status' => 'archived']);
        $this->assertSame('uncertain', array_values(IngestionSource::query()->firstOrFail()->metadata['managed_writers'])[0]['status']);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        $this->assertSame([], $this->dispatches);
    }
}
