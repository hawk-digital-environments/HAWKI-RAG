<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskPayloadService
{
    public function __construct(private ClockInterface $clock = new Clock())
    {
    }

    /**
     * @param  array<string, int>  $defaultCounters
     * @return array<string, mixed>
     */
    public function detail(PipelineTask $task, int $activeJobs, array $defaultCounters): array
    {
        return array_merge($this->summary($task, $activeJobs, $defaultCounters), [
            'jobs' => $task->jobs
                ->map(fn (PipelineJob $job) => $this->job($job))
                ->all(),
        ]);
    }

    /**
     * @param  array<string, int>  $defaultCounters
     * @return array<string, mixed>
     */
    public function summary(PipelineTask $task, int $activeJobs, array $defaultCounters): array
    {
        return [
            'taskId' => $task->task_id,
            'datasetId' => $task->dataset_id,
            'status' => $task->status,
            'startedAt' => $this->dateValue($task->started_at),
            'finishedAt' => $this->dateValue($task->finished_at),
            'counters' => $task->counters ?? $defaultCounters,
            'metadata' => $task->metadata ?? [],
            'activeJobs' => $activeJobs,
            'updatedAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function job(PipelineJob $job): array
    {
        return [
            'jobId' => $job->job_id,
            'taskId' => $job->task_id,
            'parentJobId' => $job->parent_job_id,
            'jobType' => $job->job_type,
            'sourceId' => $job->source_id,
            'sourceUrl' => $job->source_url,
            'localPath' => $job->local_path,
            'contentHash' => $job->content_hash,
            'temporalWorkflowId' => $job->temporal_workflow_id,
            'temporalRunId' => $job->temporal_run_id,
            'temporalScheduleId' => $job->temporal_schedule_id,
            'indexStatus' => $job->index_status,
            'status' => $job->status,
            'errorMessage' => $job->error_message,
            'startedAt' => $this->dateValue($job->started_at),
            'finishedAt' => $this->dateValue($job->finished_at),
            'metadata' => $job->metadata ?? [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsForJob(PipelineJob $job): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $history = is_array($metadata['events'] ?? null) ? $metadata['events'] : [];

        if ($history === []) {
            $history[] = [
                'event_type' => $metadata['latest_event_type'] ?? 'job.status',
                'event_id' => $metadata['latest_event_id'] ?? null,
                'status' => $job->status,
                'at' => $this->dateValue($job->updated_at) ?? $this->dateValue($job->started_at),
            ];
        }

        return collect($history)
            ->filter(fn (mixed $event): bool => is_array($event))
            ->map(function (array $event) use ($job): array {
                return [
                    'eventType' => $this->nullableString($event['event_type'] ?? $event['eventType'] ?? $event['event'] ?? null) ?? 'job.status',
                    'eventId' => $this->nullableString($event['event_id'] ?? $event['eventId'] ?? null),
                    'taskId' => $job->task_id,
                    'jobId' => $job->job_id,
                    'jobType' => $job->job_type,
                    'status' => $this->nullableString($event['status'] ?? null) ?? $job->status,
                    'sourceUrl' => $job->source_url,
                    'localPath' => $job->local_path,
                    'errorMessage' => $job->error_message,
                    'at' => $this->nullableString($event['at'] ?? $event['created_at'] ?? $event['createdAt'] ?? null)
                        ?? $this->dateValue($job->updated_at)
                        ?? $this->dateValue($job->started_at),
                ];
            })
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value ? (string) $value : null;
    }
}
