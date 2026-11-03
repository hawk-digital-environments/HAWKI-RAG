<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineRecoveryWorkflowService;
use App\Services\Pipeline\Recovery\PipelineRecoveryAttemptService;
use App\Services\Pipeline\Tasks\PipelineTaskRetryService;
use App\Services\TextIngestion\TextIngestionIdentifierFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class DirectTextIngestionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = storage_path('framework/testing/direct-text-recovery');
        File::deleteDirectory($this->sharedRoot);
        config()->set([
            'temporal.enabled' => true,
            'temporal.storage.shared_root' => $this->sharedRoot,
        ]);
        Http::fake(static function ($request) {
            $input = $request->data();
            if (str_ends_with($request->url(), '/source/retry-identity')) {
                return Http::response(['workflow_id' => ($input['previous_workflow_id'] ?? 'ingest-source-'.$input['source_id']).'-retry-'.$input['attempt'].'-'.preg_replace('/[^A-Za-z0-9_.-]+/', '-', $input['job_id'])]);
            }

            return Http::response(['workflow_id' => $input['workflow_id'], 'run_id' => 'run-recovered']);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_task_retry_routes_legacy_direct_text_metadata_to_text_workflow(): void
    {
        [$task, $job] = $this->failedDirectTextIngestion();

        app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);

        $job->refresh();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($task, $job): bool {
            $input = ($request->data()['workflow_input'] ?? []) ?? [];

            return str_ends_with($request->url(), '/internal/workflow-control/text/start')
                && $request->data()['workflow_id'] === app(TextIngestionIdentifierFactory::class)->workflowId($task->task_id)
                && ($input['source_id'] ?? null) === $job->source_id
                && ($input['task_id'] ?? null) === $task->task_id
                && ($input['job_id'] ?? null) === $job->job_id
                && ($input['dataset_id'] ?? null) === $task->dataset_id
                && ($input['markdown_path'] ?? null) === $job->local_path
                && ($input['content_hash'] ?? null) === $job->content_hash
                && data_get($input, 'ingestion.provider') === 'ollama'
                && data_get($input, 'ingestion.embedding_model') === 'bge-m3'
                && data_get($input, 'ingestion.collection') === 'hawki_assistant_42'
                && data_get($input, 'ingestion.neo4j_namespace') === 'hawki_assistant_42'
                && data_get($input, 'ingestion.graph') === false
                && ! array_key_exists('raw_output_path', $input);
        });
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->status);
        $this->assertSame('run-recovered', $job->temporal_run_id);
    }

    public function test_task_retry_keeps_ordinary_ingestion_on_source_workflow(): void
    {
        [$task, $job] = $this->failedOrdinaryIngestion();

        app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $input = ($request->data()['workflow_input'] ?? []) ?? [];

            return str_ends_with($request->url(), '/internal/workflow-control/source/start')
                && ($input['raw_output_path'] ?? null) === '/shared/sources/source-web/raw/';
        });
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->refresh()->status);
    }

    public function test_task_retry_does_not_restart_a_deleted_direct_text_source(): void
    {
        [$task, $job, $source] = $this->failedDirectTextIngestion();
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_DELETED,
            'metadata' => array_merge($source->metadata ?? [], [
                'text_deletion' => ['status' => IngestionSource::STATUS_DELETED],
            ]),
        ])->save();

        app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);

        Http::assertNothingSent();
        $this->assertSame(IngestionSource::STATUS_DELETED, $source->refresh()->index_status);
        $this->assertSame(PipelineJob::STATUS_FAILED, $job->refresh()->status);
    }

    public function test_conversion_recovery_reuses_scrape_output_and_current_converter_key(): void
    {
        [, $job] = $this->failedOrdinaryIngestion();
        $job->stages()->where('stage', 'ingest')->delete();
        $job->stages()->where('stage', 'convert')->update(['status' => 'failed']);
        $job->forceFill(['current_stage' => 'convert'])->save();
        config()->set('temporal.external_services.converter_token', 'updated-test-key');

        $result = app(PipelineRecoveryAttemptService::class)->retry($job, 'job', $job->job_id);

        $this->assertSame('retried', $result['result']);
        Http::assertSent(function ($request): bool {
            $input = ($request->data()['workflow_input'] ?? []);

            return ($input['resume'] ?? null) === ['stage' => 'convert', 'raw_dir' => $this->sharedRoot.'/raw']
                && $input['external_services']['converter_token'] === 'updated-test-key';
        });
        $this->assertSame('completed', $job->stages()->where('stage', 'scrape')->value('status'));
    }

    public function test_ingestion_recovery_reuses_completed_markdown(): void
    {
        [$task, $job] = $this->failedOrdinaryIngestion();
        File::deleteDirectory($this->sharedRoot.'/raw');

        app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);

        Http::assertSent(fn ($request): bool => ($request->data()['workflow_input']['resume'] ?? null) === [
            'stage' => 'ingest', 'markdown_dir' => $this->sharedRoot.'/markdown',
        ]);
        $this->assertSame('completed', $job->stages()->where('stage', 'convert')->value('status'));
    }

    public function test_missing_artifacts_returns_a_recovery_error_without_starting_a_workflow(): void
    {
        [, $job, $source] = $this->failedOrdinaryIngestion();
        File::deleteDirectory($this->sharedRoot.'/markdown');

        $result = app(PipelineRecoveryAttemptService::class)->retry($job, 'job', $job->job_id);

        $this->assertSame('failed', $result['result']);
        $this->assertStringContainsString('output directory', $result['message']);
        $this->assertSame(IngestionSource::STATUS_FAILED, $source->refresh()->index_status);
        Http::assertNothingSent();
    }

    public function test_recovery_routes_direct_text_callback_failure_to_text_workflow(): void
    {
        [$task, $job, $source] = $this->failedDirectTextIngestion();

        $result = app(PipelineRecoveryAttemptService::class)->retry(
            $job,
            'job',
            $job->job_id,
        );

        $this->assertSame('retried', $result['result']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($task, $job): bool {
            $input = ($request->data()['workflow_input'] ?? []) ?? [];

            return str_ends_with($request->url(), '/internal/workflow-control/text/start')
                && $request->data()['workflow_id'] === app(TextIngestionIdentifierFactory::class)->workflowId($task->task_id)
                && ($input['markdown_path'] ?? null) === $job->local_path
                && data_get($input, 'ingestion.graph') === false;
        });
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->refresh()->status);
        $this->assertSame(IngestionSource::STATUS_RUNNING, $source->refresh()->index_status);
    }

    public function test_recovery_keeps_ordinary_ingestion_on_source_workflow(): void
    {
        [, $job] = $this->failedOrdinaryIngestion();

        $result = app(PipelineRecoveryAttemptService::class)->retry(
            $job,
            'job',
            $job->job_id,
        );

        $this->assertSame('retried', $result['result']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/internal/workflow-control/source/start',
        ));
    }

    public function test_recovery_skips_a_deleted_direct_text_source(): void
    {
        [, $job, $source] = $this->failedDirectTextIngestion();
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_DELETED,
            'metadata' => array_merge($source->metadata ?? [], [
                'text_deletion' => ['status' => IngestionSource::STATUS_DELETED],
            ]),
        ])->save();

        $result = app(PipelineRecoveryAttemptService::class)->retry(
            $job,
            'job',
            $job->job_id,
        );

        $this->assertSame('skipped', $result['result']);
        Http::assertNothingSent();
        $this->assertSame(IngestionSource::STATUS_DELETED, $source->refresh()->index_status);
        $this->assertSame(PipelineJob::STATUS_FAILED, $job->refresh()->status);
    }

    #[TestWith([false, 'recovery'])]
    #[TestWith([false, 'task'])]
    #[TestWith([true, 'recovery'])]
    #[TestWith([true, 'task'])]
    public function test_uncertain_retry_reuses_committed_identity_payload_and_counter_across_entrypoints(bool $text, string $entrypoint): void
    {
        [$task, $job] = $text ? $this->failedDirectTextIngestion() : $this->failedOrdinaryIngestion();
        $baseline = DB::transactionLevel();
        $requests = [];
        $identityCalls = 0;
        $confirmed = false;
        Http::swap(new Factory);
        Http::fake(function ($request) use ($job, $text, $baseline, &$requests, &$identityCalls, &$confirmed) {
            $this->assertSame($baseline, DB::transactionLevel());
            if (str_ends_with($request->url(), '/source/retry-identity')) {
                $identityCalls++;
                $this->assertSame(1, $job->fresh()->metadata['pending_recovery']['attempt']);

                return Http::response(['workflow_id' => 'ingest-source-source-web-retry-1-job-web']);
            }
            $persisted = $job->fresh();
            $pending = $persisted->metadata['pending_recovery'];
            $this->assertSame($pending['workflow_id'], $persisted->temporal_workflow_id);
            $this->assertNull($persisted->temporal_run_id);
            $this->assertSame($pending['workflow_id'], $request->data()['workflow_id']);
            $this->assertSame($pending['input'], $request->data()['workflow_input']);
            $this->assertSame(hash('sha256', json_encode($pending['input'], JSON_THROW_ON_ERROR)), $pending['input_hash']);
            $this->assertSame($text ? 'IngestTextWorkflow' : 'IngestSourceWorkflow', $pending['workflow_type']);
            $this->assertStringEndsWith($text ? '/text/start' : '/source/start', $request->url());
            $requests[] = $request->data();

            return $confirmed
                ? Http::response(['workflow_id' => $pending['workflow_id'], 'run_id' => 'adopted-run'])
                : Http::response(['detail' => 'dispatch unconfirmed'], 502);
        });
        $invoke = function (string $path) use ($task, $job): void {
            if ($path === 'recovery') {
                app(PipelineRecoveryAttemptService::class)->retry($job->fresh(), 'job', $job->job_id);
            } else {
                try {
                    app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('unconfirmed', $error->getMessage());
                }
            }
        };
        $invoke($entrypoint);
        $first = $job->fresh()->metadata['pending_recovery'];
        $this->assertSame('uncertain', $first['state']);
        $this->assertSame(1, $job->fresh()->metadata['retry_count']);
        config()->set('temporal.external_services.converter_token', 'changed-after-first-preparation');
        $invoke($entrypoint === 'task' ? 'recovery' : 'task');
        $this->assertSame($first, $job->fresh()->metadata['pending_recovery']);
        $this->assertSame(1, $job->fresh()->metadata['retry_count']);
        $confirmed = true;
        $invoke('recovery');
        $this->assertCount(3, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertSame($requests[0], $requests[2]);
        $this->assertSame($text ? 0 : 1, $identityCalls);
        $this->assertSame('confirmed', $job->fresh()->metadata['pending_recovery']['state']);
        $this->assertSame('adopted-run', $job->fresh()->temporal_run_id);
        $this->assertSame(1, $job->fresh()->metadata['retry_count']);
    }

    public function test_reentrant_recovery_reserves_one_attempt_and_delayed_timeout_cannot_erase_confirmation(): void
    {
        [$task, $job] = $this->failedOrdinaryIngestion();
        $nested = false;
        Http::swap(new Factory);
        Http::fake(function ($request) use ($task, &$nested) {
            if (str_ends_with($request->url(), '/source/retry-identity')) {
                return Http::response(['workflow_id' => 'ingest-source-source-web-retry-1-job-web']);
            }
            if (! $nested) {
                $nested = true;
                app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);

                return Http::response([], 502); // Older caller loses its response.
            }

            return Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'concurrent-run']);
        });
        app(PipelineRecoveryAttemptService::class)->retry($job, 'job', $job->job_id);
        $pending = $job->fresh()->metadata['pending_recovery'];
        $this->assertSame(1, $pending['attempt']);
        $this->assertSame('confirmed', $pending['state']);
        $this->assertSame('concurrent-run', $job->fresh()->temporal_run_id);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_reserved_attempt_survives_crash_and_is_visible_to_both_recovery_entrypoints(): void
    {
        [$task, $job] = $this->failedOrdinaryIngestion();
        $service = app(PipelineRecoveryWorkflowService::class);
        $first = $service->reserve($job);
        $this->assertSame('reserved', $first->metadata['pending_recovery']['state']);
        $this->assertSame(1, $service->reserve($job)->metadata['retry_count']);
        $first->forceFill(['status' => PipelineJob::STATUS_QUEUED])->save();
        app(PipelineTaskRetryService::class)->retryFailedJobs($task->task_id);
        $this->assertSame('confirmed', $job->fresh()->metadata['pending_recovery']['state']);
        $this->assertSame(1, $job->fresh()->metadata['retry_count']);
    }

    /**
     * @return array{PipelineTask, PipelineJob, IngestionSource}
     */
    private function failedDirectTextIngestion(): array
    {
        $dataset = $this->dataset('assistant_42');
        $task = $this->task($dataset, 'task-direct');
        $markdownPath = $this->sharedRoot.'/sources/source-direct/revisions/revision/markdown/document.md';
        File::ensureDirectoryExists(dirname($markdownPath));
        File::put($markdownPath, 'Persisted direct text.');
        $contentHash = hash('sha256', 'Persisted direct text.');
        $source = IngestionSource::query()->create([
            'source_id' => 'source-direct',
            'source_url' => 'external://document-direct',
            'task_id' => $task->task_id,
            'dataset_id' => $dataset->dataset_id,
            'content_hash' => $contentHash,
            'temporal_workflow_id' => 'ingest-text-original',
            'index_status' => IngestionSource::STATUS_FAILED,
            'raw_storage_path' => null,
            'markdown_storage_path' => dirname($markdownPath),
            'metadata' => [
                'request' => [
                    'external_document_id' => 'document-direct',
                    'display_name' => 'Direct document',
                ],
                'text_ingestion' => [
                    'external_document_id' => 'document-direct',
                    'content_format' => 'markdown',
                    'markdown_path' => $markdownPath,
                ],
                'graph' => false,
            ],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-direct',
            'task_id' => $task->task_id,
            'source_id' => $source->source_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $source->source_url,
            'local_path' => $markdownPath,
            'content_hash' => $contentHash,
            'temporal_workflow_id' => 'ingest-text-original',
            'temporal_run_id' => 'run-failed',
            'status' => PipelineJob::STATUS_FAILED,
            'current_stage' => 'ingest',
            'index_status' => IngestionSource::STATUS_FAILED,
            'error_message' => 'Ready callback retries exhausted.',
            'metadata' => [
                'request' => ['external_document_id' => 'document-direct'],
                'dataset' => $this->datasetMetadata($dataset),
                'markdown_path' => $markdownPath,
                'graph' => false,
            ],
        ]);

        return [$task, $job, $source];
    }

    /**
     * @return array{PipelineTask, PipelineJob, IngestionSource}
     */
    private function failedOrdinaryIngestion(): array
    {
        File::ensureDirectoryExists($this->sharedRoot.'/raw');
        File::ensureDirectoryExists($this->sharedRoot.'/markdown');
        File::put($this->sharedRoot.'/raw/page.html', '<p>Saved scrape</p>');
        File::put($this->sharedRoot.'/markdown/page.md', '# Saved Markdown');
        $dataset = $this->dataset('web_docs');
        $task = $this->task($dataset, 'task-web');
        $source = IngestionSource::query()->create([
            'source_id' => 'source-web',
            'source_url' => 'https://example.test/page',
            'task_id' => $task->task_id,
            'dataset_id' => $dataset->dataset_id,
            'content_hash' => hash('sha256', 'web'),
            'temporal_workflow_id' => 'ingest-source-source-web',
            'index_status' => IngestionSource::STATUS_FAILED,
            'raw_storage_path' => '/shared/sources/source-web/raw/',
            'markdown_storage_path' => '/shared/sources/source-web/markdown/',
            'metadata' => [
                'dataset' => $this->datasetMetadata($dataset),
                'request' => ['metadata' => []],
            ],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-web',
            'task_id' => $task->task_id,
            'source_id' => $source->source_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $source->source_url,
            'content_hash' => $source->content_hash,
            'temporal_workflow_id' => 'ingest-source-source-web',
            'temporal_run_id' => 'run-failed',
            'status' => PipelineJob::STATUS_FAILED,
            'current_stage' => 'ingest',
            'index_status' => IngestionSource::STATUS_FAILED,
            'error_message' => 'Indexer failed.',
            'metadata' => ['dataset' => $this->datasetMetadata($dataset)],
        ]);

        foreach (['scrape' => 'raw', 'convert' => 'markdown', 'ingest' => null] as $stage => $directory) {
            PipelineStageState::query()->create([
                'pipeline_job_id' => $job->id,
                'job_id' => $job->job_id,
                'stage' => $stage,
                'status' => $directory === null ? 'failed' : 'completed',
                'metadata' => ['artifacts' => $directory === null ? [] : [
                    ['uri' => $this->sharedRoot.'/'.$directory, 'media_type' => 'inode/directory'],
                ]],
            ]);
        }

        return [$task, $job, $source];
    }

    private function dataset(string $datasetId): Dataset
    {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $datasetId,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_'.$datasetId,
            'neo4j_namespace' => 'hawki_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }

    private function task(Dataset $dataset, string $taskId): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_FAILED,
            'metadata' => ['dataset' => $this->datasetMetadata($dataset)],
            'started_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function datasetMetadata(Dataset $dataset): array
    {
        return [
            'embedding_provider' => $dataset->embedding_provider,
            'embedding_model' => $dataset->embedding_model,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }
}
