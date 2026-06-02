<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineTaskOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_orchestrated_task_creates_task_and_child_jobs_with_skips(): void
    {
        config()->set('prefect.enabled', false);
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $alreadyScrapedUrl = 'https://already.example/page';
        ScrapedElement::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Already scraped',
            'page_url' => $alreadyScrapedUrl,
            'page_url_hash' => hash('sha256', $alreadyScrapedUrl),
            'content_hash' => hash('sha256', $alreadyScrapedUrl),
            'job_id' => 'existing-scrape-job',
        ]);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-1',
            'dataset_id' => 'dataset-hawk',
            'profile_id' => 'hawk',
            'urls' => [
                $alreadyScrapedUrl,
                'https://new.example/page',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('taskId', 'task-test-1')
            ->assertJsonPath('task.counters.jobs_total', 2)
            ->assertJsonPath('task.counters.jobs_skipped', 1)
            ->assertJsonPath('task.counters.jobs_pending', 1);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-test-1',
            'dataset_id' => 'dataset-hawk',
            'profile_id' => 'hawk',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-test-1',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $alreadyScrapedUrl,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-test-1',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://new.example/page',
            'status' => PipelineJob::STATUS_PENDING,
        ]);
    }

    public function test_prefect_complete_if_idle_is_guarded_by_active_jobs(): void
    {
        config()->set('prefect.enabled', false);
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-2',
            'urls' => ['https://new.example/page'],
        ])->assertCreated();

        $this->postJson('/api/pipeline/tasks/task-test-2/complete-if-idle')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_RUNNING)
            ->assertJsonPath('task.activeJobs', 1);

        PipelineJob::query()
            ->where('task_id', 'task-test-2')
            ->update([
                'status' => PipelineJob::STATUS_COMPLETED,
                'completed_at' => now(),
                'finished_at' => now(),
            ]);

        $this->postJson('/api/pipeline/tasks/task-test-2/complete-if-idle')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_COMPLETED)
            ->assertJsonPath('task.activeJobs', 0);
    }

    public function test_task_can_be_cancelled_resumed_and_retried_without_requeueing_completed_jobs(): void
    {
        config()->set('prefect.enabled', false);
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-3',
            'urls' => ['https://new.example/page'],
        ])->assertCreated();

        $this->postJson('/api/pipeline/tasks/task-test-3/cancel')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_CANCEL_REQUESTED);

        $this->postJson('/api/pipeline/tasks/task-test-3/resume')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_RUNNING)
            ->assertJsonPath('task.counters.jobs_pending', 1);

        PipelineJob::query()
            ->where('task_id', 'task-test-3')
            ->update(['status' => PipelineJob::STATUS_FAILED]);

        $this->postJson('/api/pipeline/tasks/task-test-3/retry')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_RUNNING)
            ->assertJsonPath('task.counters.jobs_pending', 1);
    }
}
