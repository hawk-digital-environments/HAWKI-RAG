<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineFailedEventFactory
{
    public function __construct(
        private PipelineEventNormalizer $normalizer,
    ) {}

    public function make(array $event, \Throwable $error): array
    {
        return $this->normalizer->normalize(PipelineEvent::JOB_FAILED, [
            'task_id' => $event['task_id'] ?? null,
            'job_id' => $event['job_id'] ?? null,
            'parent_job_id' => $event['parent_job_id'] ?? null,
            'dataset_id' => $event['dataset_id'] ?? null,
            'job_type' => $event['job_type'] ?? null,
            'source_url' => $event['source_url'] ?? null,
            'local_path' => $event['local_path'] ?? null,
            'content_hash' => $event['content_hash'] ?? null,
            'status' => 'failed',
            'metadata' => [
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'original_event_type' => $event['event_type'] ?? null,
                'original_event_payload' => $event,
                'retry_count' => (int) ($event['retry_count'] ?? 0),
                'max_retries' => (int) ($event['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)),
            ],
        ]);
    }
}
