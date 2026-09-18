<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Status\PipelineWorkflowStatusSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PipelineWorkflowStatusSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_handoff_preserves_conversion_and_fails_pending_ingestion(): void
    {
        $job = $this->job();
        Http::fake(['*' => Http::response($this->execution('FAILED'))]);
        $result = app(PipelineWorkflowStatusSynchronizer::class)->sync($job);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Complete result exceeds size limit.', $result->error_message);
        $this->assertDatabaseHas('pipeline_stage_states', ['job_id' => $job->job_id, 'stage' => 'convert', 'status' => 'completed']);
        $this->assertDatabaseHas('pipeline_stage_states', ['job_id' => $job->job_id, 'stage' => 'ingest', 'status' => 'failed']);
    }

    public function test_running_workflow_is_not_marked_failed(): void
    {
        $job = $this->job();
        Http::fake(['*' => Http::response($this->execution('RUNNING'))]);
        $this->assertSame('running', app(PipelineWorkflowStatusSynchronizer::class)->sync($job)->status);
    }

    public function test_bridge_failure_does_not_fail_a_job(): void
    {
        $job = $this->job();
        Http::fake(['*' => Http::response([], 502)]);
        $this->assertSame('running', app(PipelineWorkflowStatusSynchronizer::class)->sync($job)->status);
    }

    public function test_a_retry_started_during_status_request_is_not_overwritten(): void
    {
        $job = $this->job();
        Http::fake(function () use ($job) {
            PipelineJob::query()->whereKey($job->id)->update(['temporal_run_id' => 'new-run']);

            return Http::response($this->execution('FAILED'));
        });
        $result = app(PipelineWorkflowStatusSynchronizer::class)->sync($job);
        $this->assertSame('running', $result->status);
        $this->assertSame('new-run', $result->temporal_run_id);
    }

    public function test_mismatched_execution_is_ignored(): void
    {
        $job = $this->job();
        Http::fake(['*' => Http::response(array_merge($this->execution('FAILED'), ['run_id' => 'old-run']))]);
        $this->assertSame('running', app(PipelineWorkflowStatusSynchronizer::class)->sync($job)->status);
    }

    private function execution(string $status): array
    {
        return ['workflow_id' => 'workflow', 'run_id' => 'run', 'status' => $status, 'error' => 'Complete result exceeds size limit.'];
    }

    private function job(): PipelineJob
    {
        $job = PipelineJob::query()->create([
            'job_id' => 'handoff-job', 'status' => 'running', 'job_type' => 'ingest',
            'current_stage' => 'ingest', 'temporal_workflow_id' => 'workflow', 'temporal_run_id' => 'run',
        ]);
        foreach (['scrape' => 'completed', 'convert' => 'completed', 'ingest' => 'pending'] as $stage => $status) {
            PipelineStageState::query()->create([
                'pipeline_job_id' => $job->id, 'job_id' => $job->job_id,
                'stage' => $stage, 'status' => $status,
            ]);
        }

        return $job;
    }
}
