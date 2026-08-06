<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Recovery\PipelineRecoveryMetadataService;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class PipelineRecoveryMetadataServiceTest extends TestCase
{
    public function test_it_builds_recovery_events_with_idempotency_key(): void
    {
        $service = new PipelineRecoveryMetadataService(new MockClock('2026-06-08T12:00:00+00:00'));

        $event = $service->recoveryEvent(
            $this->job(),
            'task',
            'task-recovery',
            2,
        );

        $this->assertStringStartsWith('recovery_', $event['event_id']);
        $this->assertSame('job.recovery_requested', $event['event']);
        $this->assertSame('task', $event['scope']);
        $this->assertSame('task-recovery', $event['scope_id']);
        $this->assertSame('job-recovery', $event['job_id']);
        $this->assertSame(2, $event['retry_count']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $event['at']);
        $this->assertSame(
            $service->idempotencyKey($this->job()),
            $event['idempotency_key'],
        );
    }

    public function test_it_appends_job_and_task_recovery_metadata(): void
    {
        $event = [
            'event_id' => 'recovery-1',
            'event' => 'job.recovery_requested',
            'retry_count' => 3,
            'at' => '2026-06-08T12:00:00+00:00',
        ];
        $service = app(PipelineRecoveryMetadataService::class);

        $jobMetadata = $service->queuedJobMetadata([
            'recovery_events' => [['event_id' => 'recovery-0']],
            'events' => [['event_type' => 'job.failed']],
        ], $event);

        $this->assertSame(3, $jobMetadata['retry_count']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $jobMetadata['retried_at']);
        $this->assertSame($event, $jobMetadata['last_recovery_event']);
        $this->assertCount(2, $jobMetadata['recovery_events']);
        $this->assertSame('job.recovery_requested', $jobMetadata['events'][1]['event_type']);

        $taskMetadata = $service->taskRecoveryMetadata(
            new PipelineTask(['metadata' => ['recovery_events' => [['event_id' => 'task-0']]]]),
            $event,
        );

        $this->assertSame($event, $taskMetadata['last_recovery_event']);
        $this->assertCount(2, $taskMetadata['recovery_events']);
    }

    public function test_it_builds_publish_failed_metadata(): void
    {
        $service = new PipelineRecoveryMetadataService(new MockClock('2026-06-08T13:00:00+00:00'));

        $metadata = $service->publishFailedJobMetadata(
            new PipelineJob(['metadata' => ['recovery_events' => [['event_id' => 'recovery-0']]]]),
            new \RuntimeException('Temporal unavailable'),
        );

        $this->assertSame('recovery_publish_failed', $metadata['last_recovery_event']['event']);
        $this->assertSame('RuntimeException', $metadata['last_recovery_event']['error_type']);
        $this->assertSame('Temporal unavailable', $metadata['last_recovery_event']['error_message']);
        $this->assertSame('2026-06-08T13:00:00+00:00', $metadata['last_recovery_event']['at']);
        $this->assertCount(2, $metadata['recovery_events']);
    }

    private function job(): PipelineJob
    {
        return new PipelineJob([
            'task_id' => 'task-recovery',
            'job_id' => 'job-recovery',
            'content_hash' => 'hash-recovery',
            'local_path' => '/app/shared/recovery.md',
            'source_url' => 'https://example.test/recovery',
        ]);
    }
}
