<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Tasks\PipelineTaskStatusService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineTaskStatusServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_exposes_active_job_statuses(): void
    {
        $this->assertSame([
            PipelineJob::STATUS_QUEUED,
            PipelineJob::STATUS_RUNNING,
        ], app(PipelineTaskStatusService::class)->activeJobStatuses());
    }

    public function test_it_keeps_running_task_running_when_it_has_no_jobs(): void
    {
        $status = app(PipelineTaskStatusService::class)->resolve(
            new PipelineTask(['status' => PipelineTask::STATUS_RUNNING]),
            [],
            false,
        );

        $this->assertSame(PipelineTask::STATUS_RUNNING, $status['status']);
        $this->assertNull($status['finished_at']);
    }

    public function test_it_marks_non_running_task_pending_when_it_has_no_jobs(): void
    {
        $status = app(PipelineTaskStatusService::class)->resolve(
            new PipelineTask(['status' => PipelineTask::STATUS_COMPLETED]),
            [],
            false,
        );

        $this->assertSame(PipelineTask::STATUS_PENDING, $status['status']);
        $this->assertNull($status['finished_at']);
    }

    public function test_it_marks_task_running_when_jobs_are_active(): void
    {
        $status = app(PipelineTaskStatusService::class)->resolve(
            new PipelineTask(['status' => PipelineTask::STATUS_FAILED, 'finished_at' => Carbon::parse('2026-06-08 11:00:00')]),
            ['queued' => 1, 'jobs_running' => 0, 'failed' => 0],
            true,
        );

        $this->assertSame(PipelineTask::STATUS_RUNNING, $status['status']);
        $this->assertNull($status['finished_at']);
    }

    public function test_it_marks_task_failed_when_no_jobs_are_active_and_any_job_failed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00'));

        $status = app(PipelineTaskStatusService::class)->resolve(
            new PipelineTask(['status' => PipelineTask::STATUS_RUNNING]),
            ['queued' => 0, 'jobs_running' => 0, 'failed' => 1],
            true,
        );

        $this->assertSame(PipelineTask::STATUS_FAILED, $status['status']);
        $this->assertTrue($status['finished_at']?->equalTo(Carbon::parse('2026-06-08 12:00:00')));
    }

    public function test_it_marks_task_completed_when_all_jobs_are_done_without_failures(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 13:00:00'));

        $status = app(PipelineTaskStatusService::class)->resolve(
            new PipelineTask(['status' => PipelineTask::STATUS_RUNNING]),
            ['queued' => 0, 'jobs_running' => 0, 'failed' => 0],
            true,
        );

        $this->assertSame(PipelineTask::STATUS_COMPLETED, $status['status']);
        $this->assertTrue($status['finished_at']?->equalTo(Carbon::parse('2026-06-08 13:00:00')));
    }
}
