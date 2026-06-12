<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
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
}
