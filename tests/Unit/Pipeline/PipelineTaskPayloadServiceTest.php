<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Tasks\PipelineTaskPayloadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineTaskPayloadServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_task_detail_and_job_payloads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00'));

        $task = new PipelineTask([
            'task_id' => 'task-payload',
            'dataset_id' => 'dataset-payload',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-06-08 11:00:00'),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['source' => 'unit-test'],
        ]);
        $task->setRelation('jobs', collect([
            new PipelineJob([
                'job_id' => 'job-payload',
                'task_id' => 'task-payload',
                'parent_job_id' => 'parent-job',
                'job_type' => PipelineJob::TYPE_SCRAPE,
                'source_url' => 'https://example.test/page',
                'local_path' => '/tmp/page.md',
                'content_hash' => 'hash-payload',
                'status' => PipelineJob::STATUS_COMPLETED,
                'started_at' => Carbon::parse('2026-06-08 11:05:00'),
                'finished_at' => Carbon::parse('2026-06-08 11:06:00'),
                'metadata' => ['label' => 'Page'],
            ]),
        ]));

        $payload = app(PipelineTaskPayloadService::class)->detail($task, 1, ['jobs_total' => 0]);

        $this->assertSame('task-payload', $payload['taskId']);
        $this->assertSame('dataset-payload', $payload['datasetId']);
        $this->assertSame(PipelineTask::STATUS_RUNNING, $payload['status']);
        $this->assertSame(['jobs_total' => 1], $payload['counters']);
        $this->assertSame(['source' => 'unit-test'], $payload['metadata']);
        $this->assertSame(1, $payload['activeJobs']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $payload['updatedAt']);
        $this->assertSame('job-payload', $payload['jobs'][0]['jobId']);
        $this->assertSame('parent-job', $payload['jobs'][0]['parentJobId']);
        $this->assertSame('https://example.test/page', $payload['jobs'][0]['sourceUrl']);
        $this->assertSame(['label' => 'Page'], $payload['jobs'][0]['metadata']);
    }

    public function test_it_builds_fallback_event_payloads_from_job_metadata(): void
    {
        $job = new PipelineJob([
            'job_id' => 'job-events',
            'task_id' => 'task-events',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/file.pdf',
            'local_path' => '/tmp/file.pdf',
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Conversion failed',
            'started_at' => Carbon::parse('2026-06-08 11:05:00'),
            'updated_at' => Carbon::parse('2026-06-08 11:10:00'),
            'metadata' => [
                'events' => [[
                    'event_type' => PipelineEvent::JOB_FAILED,
                    'event_id' => 'event-failed',
                    'status' => PipelineJob::STATUS_FAILED,
                    'at' => '2026-06-08T11:10:00+00:00',
                ]],
            ],
        ]);

        $events = app(PipelineTaskPayloadService::class)->eventsForJob($job);

        $this->assertCount(1, $events);
        $this->assertSame(PipelineEvent::JOB_FAILED, $events[0]['eventType']);
        $this->assertSame('event-failed', $events[0]['eventId']);
        $this->assertSame('task-events', $events[0]['taskId']);
        $this->assertSame('job-events', $events[0]['jobId']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $events[0]['jobType']);
        $this->assertSame(PipelineJob::STATUS_FAILED, $events[0]['status']);
        $this->assertSame('Conversion failed', $events[0]['errorMessage']);
        $this->assertSame('2026-06-08T11:10:00+00:00', $events[0]['at']);
    }

    public function test_it_builds_fallback_status_event_when_job_has_no_history(): void
    {
        $job = new PipelineJob([
            'job_id' => 'job-no-history',
            'task_id' => 'task-events',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-06-08 11:05:00'),
        ]);

        $events = app(PipelineTaskPayloadService::class)->eventsForJob($job);

        $this->assertCount(1, $events);
        $this->assertSame('job.status', $events[0]['eventType']);
        $this->assertSame('job-no-history', $events[0]['jobId']);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $events[0]['status']);
    }
}
