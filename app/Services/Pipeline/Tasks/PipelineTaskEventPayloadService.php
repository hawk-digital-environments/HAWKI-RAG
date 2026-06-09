<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventTypeRegistry;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineTaskEventPayloadService
{
    public function __construct(
        private PipelineEventTypeRegistry $types,
    ) {}

    public function retryEventType(PipelineJob $job): ?string
    {
        $metadata = $job->metadata ?? [];

        return match ($job->job_type) {
            PipelineJob::TYPE_SCRAPE => PipelineEvent::SCRAPE_REQUESTED,
            PipelineJob::TYPE_CONVERT => PipelineEvent::FILE_DISCOVERED,
            PipelineJob::TYPE_INGEST => in_array($metadata['source_event_type'] ?? null, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)
                ? (string) $metadata['source_event_type']
                : PipelineEvent::FILE_CONVERTED,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function forJob(PipelineTask $task, PipelineJob $job, string $eventType): array
    {
        $metadata = $job->metadata ?? [];
        $sourceJobId = $metadata['source_job_id'] ?? null;
        $jobId = $job->job_id;
        $jobType = $job->job_type;

        if ($job->job_type === PipelineJob::TYPE_INGEST && in_array($eventType, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)) {
            $jobId = is_string($sourceJobId) && $sourceJobId !== '' ? $sourceJobId : ($job->parent_job_id ?: $job->job_id);
            $jobType = $this->types->jobTypeFor($eventType);
        }

        return [
            'task_id' => $task->task_id,
            'job_id' => $jobId,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => $jobType,
            'source_url' => $job->source_url,
            'local_path' => $job->local_path,
            'content_hash' => $job->content_hash,
            'status' => $job->status,
            'metadata' => $metadata,
        ];
    }
}
