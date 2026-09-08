<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\TextIngestion\TextIngestionIdentifierFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class TextIngestionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $sharedRoot;

    private string $plainTextToken;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = storage_path('framework/testing/text-ingestion-api');
        File::deleteDirectory($this->sharedRoot);
        config()->set([
            'temporal.storage.shared_root' => $this->sharedRoot,
            'temporal.enabled' => true,
        ]);
        $this->user = User::query()->create([
            'username' => 'text-ingestion-api',
            'email' => 'text-ingestion-api@example.test',
            'ip' => '127.0.0.42',
        ]);
        $this->plainTextToken = $this->user
            ->createToken('text-ingestion-api', ['rag:text-ingest'])
            ->plainTextToken;
        $this->withToken($this->plainTextToken);
        $this->dataset('assistant_42');
        Http::fake([
            '*temporal/workflows/ingest-text' => static fn ($request) => Http::response([
                'workflow_id' => $request->data()['workflow_id'],
                'run_id' => 'run-123',
            ], 202),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_it_stores_text_and_starts_the_direct_workflow(): void
    {
        $response = $this->send($this->payload());

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath(
                'workflow_id',
                app(TextIngestionIdentifierFactory::class)->workflowId(
                    (string) $response->json('task_id'),
                ),
            );

        $sourceId = $response->json('source_id');
        $this->assertIsString($sourceId);
        $this->assertDatabaseHas('ingestion_sources', [
            'source_id' => $sourceId,
            'dataset_id' => 'assistant_42',
            'index_status' => 'running',
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $response->json('job_id'),
            'source_id' => $sourceId,
            'index_status' => 'running',
        ]);
        $job = PipelineJob::query()->where('job_id', $response->json('job_id'))->firstOrFail();
        $this->assertFileExists($job->local_path);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'workflow_input.ingestion.graph') === false
            && data_get($request->data(), 'workflow_input.ingestion.collection') === 'hawki_assistant_42'
            && data_get($request->data(), 'workflow_input.ingestion.embedding_model') === 'bge-m3');
    }

    public function test_same_request_is_replayed_without_starting_another_workflow(): void
    {
        $first = $this->send($this->payload());
        $second = $this->send($this->payload());

        $first->assertAccepted();
        $second
            ->assertOk()
            ->assertJsonPath('task_id', $first->json('task_id'))
            ->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
    }

    public function test_reusing_a_key_with_different_text_is_rejected(): void
    {
        $this->send($this->payload())->assertAccepted();

        $this->send([
            ...$this->payload(),
            'text' => 'Different content.',
        ])
            ->assertConflict()
            ->assertJsonPath('error', 'text_ingestion_idempotency_conflict');
        Http::assertSentCount(1);
    }

    public function test_document_text_is_stored_without_global_string_transforms(): void
    {
        $text = "  leading whitespace\n\ntrailing whitespace  \n";

        $this->send([
            ...$this->payload(),
            'text' => $text,
        ])->assertAccepted();

        $job = PipelineJob::query()->firstOrFail();
        $this->assertSame($text, File::get((string) $job->local_path));
        $this->assertSame(hash('sha256', $text), $job->content_hash);
    }

    public function test_token_authorization_and_document_text_are_not_logged(): void
    {
        $text = 'Private direct-ingestion document text.';
        $loggedRecords = [];
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->twice()
            ->withArgs(function (string $message, array $context) use (&$loggedRecords): bool {
                $loggedRecords[] = [$message, $context];

                return true;
            });
        $this->app->instance(LoggerInterface::class, $logger);

        $this->send($this->payload(['text' => $text]))->assertAccepted();

        $serializedLogs = json_encode($loggedRecords, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($text, $serializedLogs);
        $this->assertStringNotContainsString($this->plainTextToken, $serializedLogs);
        $this->assertStringNotContainsString('Authorization', $serializedLogs);
    }

    public function test_retry_with_the_same_key_recovers_an_unconfirmed_workflow_start(): void
    {
        $identifiers = app(TextIngestionIdentifierFactory::class);
        $workflowId = $identifiers->workflowId(
            $identifiers->taskId('assistant_42', 'document-123-v1'),
        );
        Http::swap(new HttpFactory($this->app['events']));
        Http::fake([
            '*temporal/workflows/ingest-text' => Http::sequence()
                ->push(['detail' => 'temporary bridge failure'], 502)
                ->push([
                    'workflow_id' => $workflowId,
                    'run_id' => 'run-123',
                ], 200),
        ]);

        $this->send($this->payload())
            ->assertStatus(502)
            ->assertJsonPath('error', 'text_ingestion_workflow_unconfirmed');

        $job = PipelineJob::query()->firstOrFail();
        $this->assertSame(
            app(TextIngestionIdentifierFactory::class)->workflowId((string) $job->task_id),
            $job->temporal_workflow_id,
        );
        $this->assertNull($job->temporal_run_id);
        $this->assertSame('temporal.workflow_starting', $job->current_stage);

        $this->send($this->payload())
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('workflow_id', $workflowId);

        $this->assertSame(
            $workflowId,
            PipelineJob::query()->firstOrFail()->temporal_workflow_id,
        );
        Http::assertSentCount(2);
    }

    public function test_a_running_source_cannot_be_replaced_by_another_request(): void
    {
        $sourceId = 'source_'.substr(
            hash('sha256', 'assistant_42|document-123'),
            0,
            32,
        );
        IngestionSource::query()->create([
            'source_id' => $sourceId,
            'source_url' => 'external://document-123',
            'task_id' => 'task-existing',
            'dataset_id' => 'assistant_42',
            'index_status' => IngestionSource::STATUS_RUNNING,
        ]);

        $this->send($this->payload())
            ->assertConflict()
            ->assertJsonPath('error', 'text_ingestion_source_busy');

        $taskId = app(TextIngestionIdentifierFactory::class)->taskId(
            'assistant_42',
            'document-123-v1',
        );
        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => $taskId]);
        Http::assertNothingSent();
    }

    public function test_db_failure_after_artifact_creation_is_marked_and_a_retry_reconciles_it(): void
    {
        $identifiers = app(TextIngestionIdentifierFactory::class);
        $taskId = $identifiers->taskId('assistant_42', 'document-123-v1');
        $sourceId = 'source_'.substr(
            hash('sha256', 'assistant_42|document-123'),
            0,
            32,
        );
        $jobId = $identifiers->jobId($taskId, $sourceId);
        $conflictingJob = PipelineJob::query()->create([
            'job_id' => $jobId,
            'status' => PipelineJob::STATUS_FAILED,
        ]);

        $this->send($this->payload())->assertServerError();

        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => $taskId]);
        $this->assertDatabaseMissing('ingestion_sources', ['source_id' => $sourceId]);
        $this->assertSame(0, PipelineTask::query()->count());
        Http::assertNothingSent();

        $markers = File::glob(
            $this->sharedRoot.DIRECTORY_SEPARATOR
            .'sources'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR
            .'revisions'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR
            .'reconciliation-required.json',
        );
        $this->assertCount(1, $markers);
        $markdownPath = dirname((string) $markers[0])
            .DIRECTORY_SEPARATOR.'markdown'.DIRECTORY_SEPARATOR.'document.md';
        $this->assertFileExists($markdownPath);
        $this->assertSame($this->payload()['text'], File::get($markdownPath));

        $conflictingJob->delete();
        $this->send($this->payload())->assertAccepted();
        $this->assertFileDoesNotExist((string) $markers[0]);
        $this->assertFileExists($markdownPath);
    }

    public function test_missing_dataset_is_rejected_before_artifacts_or_records_are_created(): void
    {
        $this->send($this->payload([
            'dataset_id' => 'missing_dataset',
        ]))
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertDatabaseMissing('datasets', ['dataset_id' => 'missing_dataset']);
        $this->assertDatabaseCount('pipeline_tasks', 0);
        $this->assertDatabaseCount('ingestion_sources', 0);
        $this->assertDatabaseCount('pipeline_jobs', 0);
        $this->assertFalse(File::exists($this->sharedRoot.DIRECTORY_SEPARATOR.'sources'));
        Http::assertNothingSent();
    }

    public function test_inactive_dataset_cannot_receive_direct_text(): void
    {
        $this->dataset('archived_assistant', Dataset::STATUS_ARCHIVED);

        $this->send($this->payload([
            'dataset_id' => 'archived_assistant',
        ]))
            ->assertConflict()
            ->assertJsonPath('error', 'dataset_inactive');

        $this->assertDatabaseCount('pipeline_tasks', 0);
        $this->assertDatabaseCount('ingestion_sources', 0);
        $this->assertDatabaseCount('pipeline_jobs', 0);
        $this->assertFalse(File::exists($this->sharedRoot.DIRECTORY_SEPARATOR.'sources'));
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/integrations/text-ingestions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainTextToken,
            'HTTP_IDEMPOTENCY_KEY' => 'document-123-v1',
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'external_document_id' => 'document-123',
            'dataset_id' => 'assistant_42',
            'text' => "# Example\n\nDocument content.",
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/document-123',
            'metadata' => ['assistant_id' => 'assistant-42'],
        ], $overrides);
    }

    private function dataset(
        string $datasetId,
        string $status = Dataset::STATUS_ACTIVE,
    ): Dataset {
        $dataset = Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => str_replace('_', ' ', ucfirst($datasetId)),
            'status' => $status,
            'qdrant_collection' => 'hawki_'.$datasetId,
            'neo4j_namespace' => 'hawki_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);

        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $this->user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_INGEST,
        ]);

        return $dataset;
    }
}
