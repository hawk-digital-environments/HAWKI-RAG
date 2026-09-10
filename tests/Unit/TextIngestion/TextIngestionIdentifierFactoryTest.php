<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\TextIngestion\TextIngestionIdentifierFactory;
use PHPUnit\Framework\TestCase;

final class TextIngestionIdentifierFactoryTest extends TestCase
{
    private TextIngestionIdentifierFactory $identifiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identifiers = new TextIngestionIdentifierFactory;
    }

    public function test_same_idempotency_key_produces_the_same_ids(): void
    {
        $firstTaskId = $this->identifiers->taskId(
            'default',
            'document-123-v1',
        );

        $secondTaskId = $this->identifiers->taskId(
            'default',
            'document-123-v1',
        );

        self::assertSame($firstTaskId, $secondTaskId);

        $firstJobId = $this->identifiers->jobId(
            $firstTaskId,
            'source-123',
        );

        $secondJobId = $this->identifiers->jobId(
            $secondTaskId,
            'source-123',
        );

        self::assertSame($firstJobId, $secondJobId);
        self::assertSame(
            $this->identifiers->workflowId($firstTaskId),
            $this->identifiers->workflowId($secondTaskId),
        );
    }

    public function test_different_idempotency_keys_produce_different_task_ids(): void
    {
        $firstTaskId = $this->identifiers->taskId(
            'default',
            'document-123-v1',
        );

        $secondTaskId = $this->identifiers->taskId(
            'default',
            'document-123-v2',
        );

        self::assertNotSame($firstTaskId, $secondTaskId);
    }

    public function test_dataset_is_part_of_the_idempotency_scope(): void
    {
        $firstTaskId = $this->identifiers->taskId(
            'dataset-one',
            'document-123-v1',
        );

        $secondTaskId = $this->identifiers->taskId(
            'dataset-two',
            'document-123-v1',
        );

        self::assertNotSame($firstTaskId, $secondTaskId);
    }

    public function test_generated_ids_have_the_expected_prefixes(): void
    {
        $taskId = $this->identifiers->taskId(
            'default',
            'document-123-v1',
        );

        self::assertStringStartsWith('task_text_', $taskId);
        self::assertStringStartsWith(
            'ingest_',
            $this->identifiers->jobId($taskId, 'source-123'),
        );
        self::assertStringStartsWith(
            'ingest-text-',
            $this->identifiers->workflowId($taskId),
        );
    }
}
