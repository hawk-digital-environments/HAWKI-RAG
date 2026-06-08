<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineScrapeMonitorRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_repository_loads_task_and_marks_scrape_monitor_completed(): void
    {
        $task = $this->task('task-scrape-monitor-repository');
        $job = PipelineJob::query()->create([
            'job_id' => 'scrape-monitor-repository-job',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/scrape-monitor',
            'content_hash' => 'hash-scrape-monitor-repository-job',
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-06-08 17:00:00'),
            'metadata' => ['dataset_id' => $task->dataset_id],
        ]);
        $completedAt = Carbon::parse('2026-06-08 17:05:00');

        $repository = app(PipelineJobRepository::class);
        $loaded = $repository->findWithTaskByJobId('scrape-monitor-repository-job');

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->relationLoaded('task'));
        $this->assertSame($task->dataset_id, $loaded->task?->dataset_id);

        $completed = $repository->markScrapeMonitorCompleted(
            $job,
            '/app/shared/scrape-monitor-repository-job',
            $completedAt,
            [
                'dataset_id' => $task->dataset_id,
                'source' => 'test',
                'crawlerStatus' => 'completed',
            ],
        );

        $this->assertSame(PipelineJob::TYPE_SCRAPE, $completed->job_type);
        $this->assertSame(PipelineJob::STATUS_COMPLETED, $completed->status);
        $this->assertSame('/app/shared/scrape-monitor-repository-job', $completed->local_path);
        $this->assertTrue($completedAt->equalTo($completed->completed_at));
        $this->assertTrue($completedAt->equalTo($completed->finished_at));
        $this->assertSame('completed', $completed->metadata['crawlerStatus']);
        $this->assertTrue($completed->relationLoaded('task'));

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'scrape-monitor-repository-job',
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => '/app/shared/scrape-monitor-repository-job',
        ]);
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
