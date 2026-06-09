<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class PipelineEventStateService
{
    public function __construct(
        private readonly PipelineTaskService $tasks,
        private readonly PipelineJobRepository $jobs,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineEventNormalizer $normalizer,
        private readonly PipelineEventTypeRegistry $types,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new Clock(),
    ) {}

    public function upsertJob(array $event, ?string $status = null, array $metadata = []): PipelineJob
    {
        $event = $this->normalizer->normalize((string) $event['event_type'], $event);
        $status = $this->normalizeStatus($status ?? (string) $event['status']);
        $terminal = $this->types->terminalStatus($status);
        $existing = $this->jobs->findByJobId((string) $event['job_id']);
        $existingMetadata = is_array($existing?->metadata) ? $existing->metadata : [];
        $mergedMetadata = array_merge($existingMetadata, $event['metadata'] ?? [], $metadata);
        $events = is_array($existingMetadata['events'] ?? null) ? $existingMetadata['events'] : [];
        $events[] = [
            'event_type' => $event['event_type'],
            'event_id' => $event['event_id'],
            'status' => $status,
            'at' => $this->timestamp(),
        ];
        $now = $this->now();

        $job = $this->jobs->upsertEventState(
            (string) $event['job_id'],
            [
                'task_id' => $event['task_id'],
                'parent_job_id' => $event['parent_job_id'],
                'job_type' => $event['job_type'],
                'source_url' => $event['source_url'],
                'local_path' => $event['local_path'],
                'content_hash' => $event['content_hash'],
                'status' => $status,
                'started_at' => $existing?->started_at ?? $now,
                'completed_at' => $terminal ? $now : null,
                'finished_at' => $terminal ? $now : null,
                'error_message' => $status === PipelineJob::STATUS_FAILED
                    ? (string) ($mergedMetadata['error_message'] ?? $mergedMetadata['last_error_message'] ?? '')
                    : null,
                'metadata' => array_merge($mergedMetadata, [
                    'latest_event_type' => $event['event_type'],
                    'latest_event_id' => $event['event_id'],
                    'events' => $events,
                ]),
            ],
        );

        $this->refreshTask((string) $event['task_id']);
        $this->logger->info('pipeline.event.state', [
            'task_id' => $event['task_id'],
            'job_id' => $event['job_id'],
            'event_type' => $event['event_type'],
            'job_type' => $event['job_type'],
            'status' => $status,
        ]);

        return $job;
    }

    public function markFailed(array $event, \Throwable $error, array $metadata = []): PipelineJob
    {
        return $this->upsertJob($event, PipelineJob::STATUS_FAILED, array_merge($metadata, [
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ]));
    }

    public function refreshTask(string $taskId): void
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if ($task) {
            $this->tasks->recalculateTaskStatus($task);
        }
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'pending' => PipelineJob::STATUS_QUEUED,
            'received',
            'processing' => PipelineJob::STATUS_RUNNING,
            'partial',
            'cancel_requested',
            'cancelled' => PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_QUEUED,
            PipelineJob::STATUS_RUNNING,
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_FAILED => $status,
            default => PipelineJob::STATUS_FAILED,
        };
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
