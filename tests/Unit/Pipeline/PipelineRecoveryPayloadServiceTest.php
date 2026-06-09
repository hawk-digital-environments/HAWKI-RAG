<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Recovery\PipelineRecoveryPayloadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineRecoveryPayloadServiceTest extends TestCase
{
    public function test_it_builds_failed_job_payloads(): void
    {
        $payload = app(PipelineRecoveryPayloadService::class)->failedJob(
            new PipelineJob([
                'task_id' => 'task-recovery',
                'job_id' => 'convert-recovery',
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => 'https://example.test/file.pdf',
                'local_path' => '/app/shared/file.pdf',
                'content_hash' => 'hash-file',
                'status' => PipelineJob::STATUS_FAILED,
                'error_message' => 'Converter failed',
                'finished_at' => Carbon::parse('2026-06-08 12:00:00'),
                'metadata' => [
                    'retry_count' => 2,
                    'last_recovery_event' => ['event' => 'job.recovery_requested'],
                ],
            ]),
            new PipelineTask(['dataset_id' => 'dataset-recovery']),
        );

        $this->assertSame('task-recovery', $payload['taskId']);
        $this->assertSame('dataset-recovery', $payload['datasetId']);
        $this->assertSame('convert-recovery', $payload['jobId']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $payload['jobType']);
        $this->assertSame('Converter failed', $payload['errorMessage']);
        $this->assertSame(2, $payload['retryCount']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $payload['timestamp']);
        $this->assertSame('job.recovery_requested', $payload['lastRecoveryEvent']['event']);
    }

    public function test_it_builds_retry_events_with_original_ingest_source_identity(): void
    {
        $task = new PipelineTask([
            'task_id' => 'task-recovery',
            'dataset_id' => 'dataset-recovery',
        ]);
        $job = new PipelineJob([
            'job_id' => 'ingest-recovery',
            'parent_job_id' => 'scrape-parent',
            'task_id' => 'task-recovery',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'https://example.test/page',
            'local_path' => '/app/shared/page.md',
            'content_hash' => 'hash-page',
            'status' => PipelineJob::STATUS_FAILED,
            'metadata' => [
                'retry_count' => 3,
                'max_retries' => 7,
                'source_event_type' => PipelineEvent::PAGE_SCRAPED,
                'source_job_id' => 'scrape-source',
            ],
        ]);

        $service = app(PipelineRecoveryPayloadService::class);
        $eventType = $service->retryEventType($job);
        $payload = $service->retryEvent($task, $job, (string) $eventType, [
            'event_id' => 'recovery-1',
            'event' => 'job.recovery_requested',
        ]);

        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $eventType);
        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $payload['event_type']);
        $this->assertSame('scrape-source', $payload['job_id']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $payload['job_type']);
        $this->assertSame(PipelineJob::STATUS_QUEUED, $payload['status']);
        $this->assertSame(3, $payload['retry_count']);
        $this->assertSame(7, $payload['max_retries']);
        $this->assertSame('recovery-1', $payload['metadata']['recovery_event']['event_id']);
        $this->assertNotEmpty($payload['metadata']['idempotency_key']);
    }

    public function test_it_returns_null_retry_event_type_for_unknown_job_types(): void
    {
        $this->assertNull(app(PipelineRecoveryPayloadService::class)->retryEventType(
            new PipelineJob(['job_type' => 'unknown']),
        ));
    }
}
