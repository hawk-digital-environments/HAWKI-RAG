<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineEventStateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_repository_upserts_event_state_jobs(): void
    {
        $repository = app(PipelineJobRepository::class);
        $startedAt = Carbon::parse('2026-06-08 15:00:00');

        $job = $repository->upsertEventState('event-state-job', [
            'task_id' => 'task-event-state-repository',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/event-state',
            'content_hash' => 'hash-event-state',
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => $startedAt,
            'metadata' => ['latest_event_type' => PipelineEvent::SCRAPE_REQUESTED],
        ]);

        $updated = $repository->upsertEventState('event-state-job', [
            'task_id' => 'task-event-state-repository',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/event-state',
            'content_hash' => 'hash-event-state',
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => $startedAt,
            'completed_at' => Carbon::parse('2026-06-08 15:05:00'),
            'finished_at' => Carbon::parse('2026-06-08 15:05:00'),
            'metadata' => ['latest_event_type' => PipelineEvent::PAGE_SCRAPED],
        ]);

        $this->assertSame($job->id, $updated->id);
        $this->assertSame(PipelineJob::STATUS_COMPLETED, $updated->status);
        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $updated->metadata['latest_event_type']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'event-state-job',
            'task_id' => 'task-event-state-repository',
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
    }

    public function test_event_state_service_upserts_job_state_and_refreshes_task(): void
    {
        $task = $this->task('task-event-state-service');

        $job = app(PipelineEventStateService::class)->upsertJob([
            'event_id' => 'event-state-service-1',
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-state-service',
            'dataset_id' => $task->dataset_id,
            'source_url' => 'https://example.test/event-state-service',
            'content_hash' => 'hash-event-state-service',
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => ['source' => 'event-state-test'],
        ]);

        $this->assertSame(PipelineJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $job->metadata['latest_event_type']);
        $this->assertSame('event-state-service-1', $job->metadata['latest_event_id']);
        $this->assertCount(1, $job->metadata['events']);

        $task->refresh();
        $this->assertSame(PipelineTask::STATUS_COMPLETED, $task->status);
        $this->assertSame(1, $task->counters['jobs_completed']);
        $this->assertSame(1, $task->counters['scraped']);
    }

    public function test_event_state_service_marks_failures_with_error_metadata(): void
    {
        $task = $this->task('task-event-state-failed');

        $job = app(PipelineEventStateService::class)->markFailed(
            [
                'event_id' => 'event-state-failed-1',
                'event_type' => PipelineEvent::FILE_DISCOVERED,
                'task_id' => $task->task_id,
                'job_id' => 'convert-event-state-failed',
                'dataset_id' => $task->dataset_id,
                'source_url' => 'https://example.test/event-state-failed.pdf',
                'content_hash' => 'hash-event-state-failed',
                'status' => PipelineJob::STATUS_RUNNING,
                'metadata' => ['source' => 'event-state-test'],
            ],
            new \RuntimeException('Converter crashed'),
            ['worker' => 'converter'],
        );

        $this->assertSame(PipelineJob::STATUS_FAILED, $job->status);
        $this->assertSame('Converter crashed', $job->error_message);
        $this->assertSame('RuntimeException', $job->metadata['error_type']);
        $this->assertSame('converter', $job->metadata['worker']);

        $task->refresh();
        $this->assertSame(PipelineTask::STATUS_FAILED, $task->status);
        $this->assertSame(1, $task->counters['jobs_failed']);
    }

    private function task(string $taskId): PipelineTask
    {
        $dataset = Dataset::query()->create([
            'dataset_id' => $taskId . '-dataset',
            'name' => $taskId . ' Dataset',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_' . str_replace('-', '_', $taskId),
            'neo4j_namespace' => 'hawki_' . str_replace('-', '_', $taskId),
            'created_at' => now(),
        ]);

        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);
    }
}
