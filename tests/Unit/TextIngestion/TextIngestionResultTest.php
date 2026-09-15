<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\TextIngestion\Values\TextIngestionResult;
use PHPUnit\Framework\TestCase;

final class TextIngestionResultTest extends TestCase
{
    public function test_it_creates_a_started_result(): void
    {
        $result = TextIngestionResult::started(
            taskId: 'task-123',
            jobId: 'job-123',
            sourceId: 'source-123',
            workflowId: 'workflow-123',
        );

        self::assertSame('task-123', $result->taskId);
        self::assertSame('job-123', $result->jobId);
        self::assertSame('source-123', $result->sourceId);
        self::assertSame('workflow-123', $result->workflowId);
        self::assertSame('running', $result->status);
        self::assertFalse($result->replayed);

        self::assertSame([
            'task_id' => 'task-123',
            'job_id' => 'job-123',
            'source_id' => 'source-123',
            'workflow_id' => 'workflow-123',
            'status' => 'running',
            'replayed' => false,
        ], $result->toArray());
    }

    public function test_it_creates_an_idempotent_replayed_result(): void
    {
        $result = TextIngestionResult::replayed(
            taskId: 'task-123',
            jobId: 'job-123',
            sourceId: 'source-123',
            workflowId: 'workflow-123',
            status: 'ready',
        );

        self::assertSame('task-123', $result->taskId);
        self::assertSame('job-123', $result->jobId);
        self::assertSame('source-123', $result->sourceId);
        self::assertSame('workflow-123', $result->workflowId);
        self::assertSame('ready', $result->status);
        self::assertTrue($result->replayed);
    }

    public function test_a_replayed_result_can_have_no_workflow_id(): void
    {
        $result = TextIngestionResult::replayed(
            taskId: 'task-123',
            jobId: 'job-123',
            sourceId: 'source-123',
            workflowId: null,
            status: 'failed',
        );

        self::assertNull($result->workflowId);
        self::assertSame('failed', $result->status);
        self::assertTrue($result->replayed);
    }
}
