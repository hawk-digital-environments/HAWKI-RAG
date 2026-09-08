<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

final readonly class TextIngestionResult
{
    private function __construct(
        public string $taskId,
        public string $jobId,
        public string $sourceId,
        public ?string $workflowId,
        public string $status,
        public bool $replayed,
    ) {}

    public static function started(
        string $taskId,
        string $jobId,
        string $sourceId,
        string $workflowId,
    ): self {
        return new self(
            taskId: $taskId,
            jobId: $jobId,
            sourceId: $sourceId,
            workflowId: $workflowId,
            status: 'running',
            replayed: false,
        );
    }

    public static function replayed(
        string $taskId,
        string $jobId,
        string $sourceId,
        ?string $workflowId,
        string $status,
    ): self {
        return new self(
            taskId: $taskId,
            jobId: $jobId,
            sourceId: $sourceId,
            workflowId: $workflowId,
            status: $status,
            replayed: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'job_id' => $this->jobId,
            'source_id' => $this->sourceId,
            'workflow_id' => $this->workflowId,
            'status' => $this->status,
            'replayed' => $this->replayed,
        ];
    }
}
