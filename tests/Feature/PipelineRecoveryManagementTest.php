<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineEventRecord;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PipelineRecoveryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_jobs_page_and_api_show_failed_jobs_with_retry_metadata(): void
    {
        $this->withoutVite();
        $this->dataset('dataset-recovery');
        $this->task('task-recovery-list', 'dataset-recovery');
        $this->failedJob('convert-recovery-list', 'task-recovery-list', PipelineJob::TYPE_CONVERT, [
            'retry_count' => 2,
        ]);

        $this->get('/failed-jobs')
            ->assertOk()
            ->assertSee('Failed Jobs')
            ->assertSee('Recovery controls');

        $this->getJson('/api/pipeline/recovery/failed-jobs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.taskId', 'task-recovery-list')
            ->assertJsonPath('jobs.0.datasetId', 'dataset-recovery')
            ->assertJsonPath('jobs.0.jobId', 'convert-recovery-list')
            ->assertJsonPath('jobs.0.jobType', PipelineJob::TYPE_CONVERT)
            ->assertJsonPath('jobs.0.errorMessage', 'Worker failed')
            ->assertJsonPath('jobs.0.retryCount', 2);
    }

    public function test_operator_can_retry_selected_failed_job_idempotently(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);
        $this->dataset('dataset-retry-selected');
        $this->task('task-retry-selected', 'dataset-retry-selected');
        $this->failedJob('convert-retry-selected', 'task-retry-selected', PipelineJob::TYPE_CONVERT);

        $this->postJson('/api/pipeline/recovery/jobs/retry-selected', [
            'job_ids' => ['convert-retry-selected'],
        ])
            ->assertOk()
            ->assertJsonPath('recovery.attempted', 1)
            ->assertJsonPath('recovery.retried', 1)
            ->assertJsonPath('recovery.jobs.0.result', 'retried')
            ->assertJsonPath('recovery.jobs.0.status', PipelineJob::STATUS_QUEUED)
            ->assertJsonPath('recovery.jobs.0.retryCount', 1);

        $job = PipelineJob::query()->where('job_id', 'convert-retry-selected')->firstOrFail();
        $this->assertSame(PipelineJob::STATUS_QUEUED, $job->status);
        $this->assertNull($job->error_message);
        $this->assertSame(1, $job->metadata['retry_count'] ?? null);
        $this->assertSame('job.recovery_requested', $job->metadata['last_recovery_event']['event'] ?? null);
        $this->assertSame('convert-retry-selected', $job->metadata['last_recovery_event']['job_id'] ?? null);
        $this->assertNotEmpty($job->metadata['last_recovery_event']['idempotency_key'] ?? null);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-retry-selected',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'task_id' => 'task-retry-selected',
            'job_id' => 'convert-retry-selected',
            'event_type' => PipelineEvent::FILE_DISCOVERED,
            'source' => 'rabbitmq.recovery',
        ]);

        $this->postJson('/api/pipeline/recovery/jobs/retry-selected', [
            'job_ids' => ['convert-retry-selected'],
        ])
            ->assertOk()
            ->assertJsonPath('recovery.attempted', 1)
            ->assertJsonPath('recovery.retried', 0)
            ->assertJsonPath('recovery.skipped', 1);

        $job->refresh();
        $this->assertSame(PipelineJob::STATUS_QUEUED, $job->status);
        $this->assertSame(1, $job->metadata['retry_count'] ?? null);
        $this->assertSame(1, PipelineEventRecord::query()
            ->where('task_id', 'task-retry-selected')
            ->where('job_id', 'convert-retry-selected')
            ->where('source', 'rabbitmq.recovery')
            ->count());
    }

    public function test_operator_can_retry_failed_jobs_by_task_dataset_and_all(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);
        $this->dataset('dataset-scope-a');
        $this->dataset('dataset-scope-b');
        $this->task('task-scope-a1', 'dataset-scope-a');
        $this->task('task-scope-a2', 'dataset-scope-a');
        $this->task('task-scope-b1', 'dataset-scope-b');
        $this->failedJob('scrape-scope-a1', 'task-scope-a1', PipelineJob::TYPE_SCRAPE);
        $this->failedJob('convert-scope-a2', 'task-scope-a2', PipelineJob::TYPE_CONVERT);
        $this->failedJob('ingest-scope-b1', 'task-scope-b1', PipelineJob::TYPE_INGEST, [
            'source_event_type' => PipelineEvent::PAGE_SCRAPED,
            'source_job_id' => 'scrape-source-b1',
        ]);

        $this->postJson('/api/pipeline/recovery/tasks/task-scope-a1/retry-failed')
            ->assertOk()
            ->assertJsonPath('recovery.retried', 1);
        $this->assertSame(PipelineJob::STATUS_QUEUED, PipelineJob::query()->where('job_id', 'scrape-scope-a1')->value('status'));
        $this->assertSame(PipelineJob::STATUS_FAILED, PipelineJob::query()->where('job_id', 'convert-scope-a2')->value('status'));
        $this->assertSame(PipelineJob::STATUS_FAILED, PipelineJob::query()->where('job_id', 'ingest-scope-b1')->value('status'));

        $this->postJson('/api/pipeline/recovery/datasets/dataset-scope-a/retry-failed')
            ->assertOk()
            ->assertJsonPath('recovery.retried', 1);
        $this->assertSame(PipelineJob::STATUS_QUEUED, PipelineJob::query()->where('job_id', 'convert-scope-a2')->value('status'));
        $this->assertSame(PipelineJob::STATUS_FAILED, PipelineJob::query()->where('job_id', 'ingest-scope-b1')->value('status'));

        $this->postJson('/api/pipeline/recovery/retry-all')
            ->assertOk()
            ->assertJsonPath('recovery.retried', 1);
        $this->assertSame(PipelineJob::STATUS_QUEUED, PipelineJob::query()->where('job_id', 'ingest-scope-b1')->value('status'));
        $this->assertSame(0, PipelineJob::query()->where('status', PipelineJob::STATUS_FAILED)->count());
    }

    public function test_recovery_uses_existing_rabbitmq_retry_publisher_with_original_ingest_source_identity(): void
    {
        $this->dataset('dataset-rabbit-retry');
        $this->task('task-rabbit-retry', 'dataset-rabbit-retry');
        $this->failedJob('ingest-rabbit-retry', 'task-rabbit-retry', PipelineJob::TYPE_INGEST, [
            'source_event_type' => PipelineEvent::PAGE_SCRAPED,
            'source_job_id' => 'scrape-rabbit-source',
        ]);

        $this->mock(PipelineEventBus::class, function ($mock): void {
            $mock->shouldReceive('publishRecoveryRetry')
                ->once()
                ->with(Mockery::on(function (array $event): bool {
                    return $event['event_type'] === PipelineEvent::PAGE_SCRAPED
                        && $event['task_id'] === 'task-rabbit-retry'
                        && $event['job_id'] === 'scrape-rabbit-source'
                        && $event['job_type'] === PipelineJob::TYPE_SCRAPE
                        && $event['status'] === PipelineJob::STATUS_QUEUED
                        && $event['content_hash'] === 'hash-ingest-rabbit-retry'
                        && ($event['metadata']['source_job_id'] ?? null) === 'scrape-rabbit-source'
                        && !empty($event['metadata']['idempotency_key']);
                }), Mockery::type('string'))
                ->andReturnUsing(fn (array $event, string $reason): array => PipelineEvent::normalize($event['event_type'], $event));
        });

        $this->postJson('/api/pipeline/recovery/jobs/ingest-rabbit-retry/retry')
            ->assertOk()
            ->assertJsonPath('recovery.retried', 1)
            ->assertJsonPath('recovery.jobs.0.publishedEventType', PipelineEvent::PAGE_SCRAPED);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'ingest-rabbit-retry',
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
        ]);
    }

    private function dataset(string $datasetId): Dataset
    {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $datasetId,
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_' . str_replace('-', '_', $datasetId),
            'neo4j_namespace' => 'hawki_' . str_replace('-', '_', $datasetId),
            'created_at' => now(),
        ]);
    }

    private function task(string $taskId, string $datasetId): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $datasetId,
            'status' => PipelineTask::STATUS_FAILED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinute(),
            'counters' => ['failed' => 1],
            'metadata' => [],
        ]);
    }

    private function failedJob(string $jobId, string $taskId, string $jobType, array $metadata = []): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $taskId,
            'parent_job_id' => $metadata['source_job_id'] ?? null,
            'job_type' => $jobType,
            'source_url' => 'https://example.test/' . $jobId,
            'local_path' => '/app/shared/' . $jobId . '.md',
            'content_hash' => 'hash-' . $jobId,
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Worker failed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinute(),
            'metadata' => $metadata,
        ]);
    }
}
