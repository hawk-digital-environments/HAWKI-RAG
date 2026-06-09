<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineEventNormalizer
{
    public function __construct(
        private PipelineEventTypeRegistry $types,
        private ClockInterface $clock = new Clock,
        private ?PipelineEventConfig $config = null,
    ) {}

    public function normalize(string $eventType, array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $taskId = $this->scalar($payload['task_id'] ?? $payload['taskId'] ?? null);
        $sourceUrl = $this->scalar($payload['source_url'] ?? $payload['sourceUrl'] ?? null);
        $localPath = $this->scalar($payload['local_path'] ?? $payload['localPath'] ?? $payload['converted_path'] ?? null);
        $contentHash = $this->scalar($payload['content_hash'] ?? $payload['contentHash'] ?? null);
        $jobType = $this->scalar($payload['job_type'] ?? $payload['jobType'] ?? null) ?? $this->types->jobTypeFor($eventType);
        $jobId = $this->scalar($payload['job_id'] ?? $payload['jobId'] ?? null)
            ?? $this->deterministicJobId($eventType, $taskId, $sourceUrl, $localPath, $contentHash);

        return [
            'event_id' => $this->scalar($payload['event_id'] ?? null) ?? (string) Str::uuid(),
            'event_type' => $eventType,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'parent_job_id' => $this->scalar($payload['parent_job_id'] ?? $payload['parentJobId'] ?? null),
            'dataset_id' => $this->scalar($payload['dataset_id'] ?? $payload['datasetId'] ?? null),
            'job_type' => $jobType,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'content_hash' => $contentHash,
            'status' => $this->scalar($payload['status'] ?? null) ?? PipelineJob::STATUS_QUEUED,
            'created_at' => $this->scalar($payload['created_at'] ?? $payload['createdAt'] ?? null) ?? $this->timestamp(),
            'metadata' => $metadata,
            'retry_count' => max(0, (int) ($payload['retry_count'] ?? 0)),
            'max_retries' => max(0, (int) ($payload['max_retries'] ?? $this->maxRetries())),
            'schema_version' => $this->scalar($payload['schema_version'] ?? null) ?? $this->schemaVersion(),
            'source' => $this->scalar($payload['source'] ?? null) ?? 'hawki-rag-laravel',
        ];
    }

    private function deterministicJobId(
        string $eventType,
        ?string $taskId,
        ?string $sourceUrl,
        ?string $localPath,
        ?string $contentHash,
    ): string {
        return $this->types->jobIdPrefixFor($eventType).'_'.substr(hash('sha256', implode('|', [
            $eventType,
            $taskId ?? '',
            $sourceUrl ?? '',
            $localPath ?? '',
            $contentHash ?? '',
        ])), 0, 24);
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    private function maxRetries(): int
    {
        return $this->config?->maxRetries() ?? 3;
    }

    private function schemaVersion(): string
    {
        return $this->config?->schemaVersion() ?? '1';
    }
}
