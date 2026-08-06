<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Services\Pipeline\Tasks\PipelineTaskInputNormalizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineTaskInputNormalizerTest extends TestCase
{
    public function test_it_normalizes_task_and_job_ids(): void
    {
        $normalizer = app(PipelineTaskInputNormalizer::class);

        $this->assertSame('task-provided', $normalizer->taskId(['task_id' => ' task-provided ']));
        $this->assertSame('task-camel', $normalizer->taskId(['taskId' => 'task-camel']));
        $this->assertStringStartsWith('task_', $normalizer->taskId([]));

        $this->assertSame('job-provided', $normalizer->jobId(['job_id' => ' job-provided ']));
        $this->assertSame('job-camel', $normalizer->jobId(['jobId' => 'job-camel']));
        $this->assertNotSame('', $normalizer->jobId([]));
    }

    public function test_it_normalizes_nullable_strings(): void
    {
        $normalizer = app(PipelineTaskInputNormalizer::class);

        $this->assertSame('value', $normalizer->nullableString(' value '));
        $this->assertSame('123', $normalizer->nullableString(123));
        $this->assertNull($normalizer->nullableString('   '));
        $this->assertNull($normalizer->nullableString(['value']));
    }

    public function test_it_normalizes_job_statuses(): void
    {
        $normalizer = app(PipelineTaskInputNormalizer::class);

        $this->assertSame(PipelineJob::STATUS_QUEUED, $normalizer->jobStatus(null));
        $this->assertSame(PipelineJob::STATUS_QUEUED, $normalizer->jobStatus('pending'));
        $this->assertSame(PipelineJob::STATUS_RUNNING, $normalizer->jobStatus('received'));
        $this->assertSame(PipelineJob::STATUS_RUNNING, $normalizer->jobStatus('processing'));
        $this->assertSame(PipelineJob::STATUS_FAILED, $normalizer->jobStatus('partial'));
        $this->assertSame(PipelineJob::STATUS_FAILED, $normalizer->jobStatus('cancel_requested'));
        $this->assertSame(PipelineJob::STATUS_FAILED, $normalizer->jobStatus('cancelled'));
        $this->assertSame(PipelineJob::STATUS_COMPLETED, $normalizer->jobStatus(PipelineJob::STATUS_COMPLETED));
        $this->assertSame(PipelineJob::STATUS_SKIPPED, $normalizer->jobStatus(PipelineJob::STATUS_SKIPPED));
        $this->assertSame(PipelineJob::STATUS_FAILED, $normalizer->jobStatus('unexpected'));
    }

    public function test_it_detects_terminal_statuses(): void
    {
        $normalizer = app(PipelineTaskInputNormalizer::class);

        $this->assertTrue($normalizer->isTerminalStatus(PipelineJob::STATUS_COMPLETED));
        $this->assertTrue($normalizer->isTerminalStatus(PipelineJob::STATUS_SKIPPED));
        $this->assertTrue($normalizer->isTerminalStatus(PipelineJob::STATUS_FAILED));
        $this->assertFalse($normalizer->isTerminalStatus(PipelineJob::STATUS_RUNNING));
        $this->assertFalse($normalizer->isTerminalStatus(PipelineJob::STATUS_QUEUED));
    }

    public function test_it_normalizes_date_inputs(): void
    {
        $normalizer = app(PipelineTaskInputNormalizer::class);
        $carbon = Carbon::parse('2026-06-08 12:00:00');
        $dateTime = new \DateTimeImmutable('2026-06-08 13:00:00');

        $this->assertSame($carbon, $normalizer->date($carbon));
        $this->assertTrue($normalizer->date($dateTime)?->equalTo(Carbon::parse('2026-06-08 13:00:00')));
        $this->assertTrue($normalizer->date('2026-06-08 14:00:00')?->equalTo(Carbon::parse('2026-06-08 14:00:00')));
        $this->assertNull($normalizer->date(null));
        $this->assertNull($normalizer->date(''));
    }
}
