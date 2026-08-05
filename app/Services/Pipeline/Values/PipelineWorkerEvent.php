<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

final readonly class PipelineWorkerEvent
{
    public const EVENT_TYPE = 'pipeline.stage.status';

    /**
     * @param  array{total:int,processed:int,failed:int,skipped:int}  $counts
     * @param  array<string, mixed>  $metrics
     * @param  list<array{uri:string,relative_path?:string|null,sha256?:string|null,size_bytes?:int|null,media_type?:string|null}>  $artifacts
     * @param  array{uri:string,relative_path?:string|null,sha256?:string|null,size_bytes?:int|null,media_type?:string|null}|null  $manifest
     * @param  list<array{code:string,message:string,retryable:bool}>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public int $schemaVersion,
        public string $eventId,
        public string $eventType,
        public PipelineWorker $producer,
        public string $occurredAt,
        public \DateTimeImmutable $occurredAtInstant,
        public string $workflowId,
        public string $runId,
        public string $activityId,
        public int $attempt,
        public string $jobId,
        public ?string $taskId,
        public string $sourceId,
        public PipelineStage $stage,
        public string $phase,
        public PipelineStageStatus $status,
        public array $counts,
        public array $metrics,
        public array $artifacts,
        public ?array $manifest,
        public array $errors,
        public array $warnings,
        public ?string $errorDetails,
        public ?string $documentVersion,
        public string $payloadHash,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data, string $rawBody): self
    {
        $occurredAt = (string) $data['timestamp'];
        try {
            $occurredAtInstant = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            throw new \LogicException('Validated worker event contains an invalid timestamp value.');
        }

        /** @var array<string, mixed> $rawCounts */
        $rawCounts = $data['counts'] ?? [];
        /** @var array<string, mixed> $rawMetrics */
        $rawMetrics = $data['metrics'] ?? [];

        $counts = [
            'total' => (int) ($rawCounts['total'] ?? 0),
            'processed' => (int) ($rawCounts['processed'] ?? 0),
            'failed' => (int) ($rawCounts['failed'] ?? 0),
            'skipped' => (int) ($rawCounts['skipped'] ?? 0),
        ];

        $errors = [];
        foreach (($data['errors'] ?? []) as $error) {
            $errors[] = [
                'code' => (string) $error['code'],
                'message' => (string) $error['message'],
                'retryable' => (bool) ($error['retryable'] ?? false),
            ];
        }

        return new self(
            schemaVersion: (int) $data['schema_version'],
            eventId: (string) $data['event_id'],
            eventType: (string) $data['event_type'],
            producer: PipelineWorker::from((string) $data['producer']),
            occurredAt: $occurredAt,
            occurredAtInstant: $occurredAtInstant,
            workflowId: (string) $data['workflow_id'],
            runId: (string) $data['run_id'],
            activityId: (string) $data['activity_id'],
            attempt: (int) $data['attempt'],
            jobId: (string) $data['job_id'],
            taskId: isset($data['task_id']) ? (string) $data['task_id'] : null,
            sourceId: (string) $data['source_id'],
            stage: PipelineStage::from((string) $data['stage']),
            phase: (string) $data['phase'],
            status: PipelineStageStatus::from((string) $data['status']),
            counts: $counts,
            metrics: $rawMetrics,
            artifacts: array_values($data['artifacts'] ?? []),
            manifest: isset($data['manifest']) && is_array($data['manifest']) ? $data['manifest'] : null,
            errors: $errors,
            warnings: array_values(array_map('strval', $data['warnings'] ?? [])),
            errorDetails: isset($data['error_details']) ? (string) $data['error_details'] : null,
            documentVersion: isset($data['document_version']) ? (string) $data['document_version'] : null,
            payloadHash: hash('sha256', $rawBody),
            payload: $data,
        );
    }

    public function errorMessage(): ?string
    {
        $message = $this->errors[0]['message'] ?? $this->errorDetails;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
