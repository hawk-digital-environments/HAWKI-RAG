<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PipelineRecoveryService
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineTaskService $tasks,
    ) {
    }

    public function failedJobs(array $filters = []): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));

        return $this->failedJobsQuery($filters)
            ->with('task')
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PipelineJob $job): array => $this->failedJobPayload($job))
            ->all();
    }

    public function retrySelected(array $jobIds): array
    {
        $jobIds = array_values(array_unique(array_filter(array_map(
            fn (mixed $jobId): ?string => is_scalar($jobId) && trim((string) $jobId) !== '' ? trim((string) $jobId) : null,
            $jobIds,
        ))));

        if ($jobIds === []) {
            return $this->emptyResult('selected');
        }

        $jobs = PipelineJob::query()
            ->whereIn('job_id', $jobIds)
            ->get();

        return $this->retryJobs($jobs, 'selected');
    }

    public function retryJob(string $jobId): array
    {
        $job = PipelineJob::query()
            ->where('job_id', $jobId)
            ->first();

        return $this->retryJobs($job ? collect([$job]) : collect(), 'job', $jobId);
    }

    public function retryAll(): array
    {
        return $this->retryJobs($this->failedJobsQuery()->get(), 'all');
    }

    public function retryTask(string $taskId): array
    {
        return $this->retryJobs($this->failedJobsQuery(['task_id' => $taskId])->get(), 'task', $taskId);
    }

    public function retryDataset(string $datasetId): array
    {
        return $this->retryJobs($this->failedJobsQuery(['dataset_id' => $datasetId])->get(), 'dataset', $datasetId);
    }

    private function failedJobsQuery(array $filters = [])
    {
        $taskId = $this->stringValue($filters['task_id'] ?? $filters['taskId'] ?? null);
        $datasetId = $this->stringValue($filters['dataset_id'] ?? $filters['datasetId'] ?? null);

        return PipelineJob::query()
            ->where('status', PipelineJob::STATUS_FAILED)
            ->when($taskId, fn ($query) => $query->where('task_id', $taskId))
            ->when($datasetId, fn ($query) => $query->whereHas('task', fn ($taskQuery) => $taskQuery->where('dataset_id', $datasetId)));
    }

    private function retryJobs(Collection $jobs, string $scope, ?string $scopeId = null): array
    {
        $result = $this->emptyResult($scope, $scopeId);

        foreach ($jobs as $job) {
            $attempt = $this->retryOne($job, $scope, $scopeId);
            $result['jobs'][] = $attempt;
            $result['attempted']++;
            $result[$attempt['result']] = ($result[$attempt['result']] ?? 0) + 1;
        }

        return $result;
    }

    private function retryOne(PipelineJob $job, string $scope, ?string $scopeId): array
    {
        $prepared = DB::transaction(function () use ($job, $scope, $scopeId): array {
            $locked = PipelineJob::query()
                ->whereKey($job->getKey())
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return [
                    'result' => 'skipped',
                    'jobId' => $job->job_id,
                    'message' => 'Job no longer exists.',
                ];
            }

            if ($locked->status !== PipelineJob::STATUS_FAILED) {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => "Job is {$locked->status}; only failed jobs are retried.",
                ];
            }

            $task = PipelineTask::query()
                ->where('task_id', $locked->task_id)
                ->first();
            if (!$task) {
                return [
                    'result' => 'failed',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => 'Parent task was not found.',
                ];
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if ($this->eventTypeForJob($locked, $metadata) === null) {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => "Job type {$locked->job_type} cannot be retried through RabbitMQ recovery.",
                ];
            }

            $retryCount = (int) ($metadata['retry_count'] ?? 0) + 1;
            $recoveryEvent = $this->recoveryEvent($locked, $scope, $scopeId, $retryCount);
            $metadata['retry_count'] = $retryCount;
            $metadata['retried_at'] = $recoveryEvent['at'];
            $metadata['last_recovery_event'] = $recoveryEvent;
            $metadata['recovery_events'] = array_merge(
                is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
                [$recoveryEvent],
            );
            $metadata['events'] = array_merge(
                is_array($metadata['events'] ?? null) ? $metadata['events'] : [],
                [[
                    'event_type' => 'job.recovery_requested',
                    'event_id' => $recoveryEvent['event_id'],
                    'status' => PipelineJob::STATUS_QUEUED,
                    'at' => $recoveryEvent['at'],
                ]],
            );

            $locked->forceFill([
                'status' => PipelineJob::STATUS_QUEUED,
                'error_message' => null,
                'finished_at' => null,
                'completed_at' => null,
                'metadata' => $metadata,
            ])->save();

            $task->forceFill([
                'status' => PipelineTask::STATUS_RUNNING,
                'finished_at' => null,
                'metadata' => $this->taskRecoveryMetadata($task, $recoveryEvent),
            ])->save();

            return [
                'result' => 'prepared',
                'job' => $locked->refresh(),
                'task' => $task->refresh(),
                'event' => $this->eventPayloadForJob($task->refresh(), $locked->refresh(), $recoveryEvent),
                'recoveryEvent' => $recoveryEvent,
            ];
        });

        if (($prepared['result'] ?? null) !== 'prepared') {
            return $prepared;
        }

        /** @var PipelineJob $preparedJob */
        $preparedJob = $prepared['job'];
        try {
            $published = $this->events->publishRecoveryRetry(
                $prepared['event'],
                sprintf('operator %s recovery%s', $scope, $scopeId ? " {$scopeId}" : ''),
            );
            $this->tasks->recalculateTaskStatus((string) $preparedJob->task_id);
            Log::info('pipeline.recovery.retry_queued', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'task_id' => $preparedJob->task_id,
                'job_id' => $preparedJob->job_id,
                'event_type' => $published['event_type'] ?? null,
                'retry_count' => $published['retry_count'] ?? null,
            ]);

            return array_merge($this->failedJobPayload($preparedJob->refresh()), [
                'result' => 'retried',
                'message' => 'Recovery retry queued.',
                'publishedEventType' => $published['event_type'] ?? null,
            ]);
        } catch (Throwable $error) {
            $failed = $this->markPublishFailed($preparedJob, $error);

            return array_merge($this->failedJobPayload($failed), [
                'result' => 'failed',
                'message' => 'Recovery retry could not be published: ' . $error->getMessage(),
            ]);
        }
    }

    private function markPublishFailed(PipelineJob $job, Throwable $error): PipelineJob
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $event = [
            'event_id' => 'recovery_' . Str::uuid()->toString(),
            'event' => 'recovery_publish_failed',
            'at' => now()->toIso8601String(),
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ];
        $metadata['last_recovery_event'] = $event;
        $metadata['recovery_events'] = array_merge(
            is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
            [$event],
        );

        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => $error->getMessage(),
            'finished_at' => Carbon::now(),
            'metadata' => $metadata,
        ])->save();

        $this->tasks->recalculateTaskStatus((string) $job->task_id);

        return $job->refresh();
    }

    private function failedJobPayload(PipelineJob $job): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $task = $job->relationLoaded('task') ? $job->task : $job->task()->first();

        return [
            'taskId' => $job->task_id,
            'datasetId' => $task?->dataset_id,
            'jobId' => $job->job_id,
            'jobType' => $job->job_type,
            'sourceUrl' => $job->source_url,
            'localPath' => $job->local_path,
            'contentHash' => $job->content_hash,
            'status' => $job->status,
            'errorMessage' => $job->error_message,
            'retryCount' => (int) ($metadata['retry_count'] ?? 0),
            'timestamp' => ($job->finished_at ?? $job->updated_at ?? $job->created_at)?->format(DATE_ATOM),
            'lastRecoveryEvent' => is_array($metadata['last_recovery_event'] ?? null) ? $metadata['last_recovery_event'] : null,
        ];
    }

    private function eventPayloadForJob(PipelineTask $task, PipelineJob $job, array $recoveryEvent): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $eventType = $this->eventTypeForJob($job, $metadata);
        if ($eventType === null) {
            throw new \RuntimeException("Job type {$job->job_type} cannot be retried through RabbitMQ recovery.");
        }

        $sourceJobId = $metadata['source_job_id'] ?? null;
        $jobId = $job->job_id;
        $jobType = $job->job_type;

        if ($job->job_type === PipelineJob::TYPE_INGEST && in_array($eventType, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)) {
            $jobId = is_string($sourceJobId) && $sourceJobId !== '' ? $sourceJobId : ($job->parent_job_id ?: $job->job_id);
            $jobType = PipelineEvent::jobTypeFor($eventType);
        }

        return [
            'event_type' => $eventType,
            'task_id' => $task->task_id,
            'job_id' => $jobId,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task->dataset_id,
            'profile_id' => $task->profile_id,
            'job_type' => $jobType,
            'source_url' => $job->source_url,
            'local_path' => $job->local_path,
            'content_hash' => $job->content_hash,
            'status' => PipelineJob::STATUS_QUEUED,
            'retry_count' => (int) ($metadata['retry_count'] ?? 0),
            'max_retries' => (int) ($metadata['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)),
            'metadata' => array_merge($metadata, [
                'recovery_event' => $recoveryEvent,
                'idempotency_key' => $this->idempotencyKey($job),
            ]),
        ];
    }

    private function eventTypeForJob(PipelineJob $job, array $metadata): ?string
    {
        return match ($job->job_type) {
            PipelineJob::TYPE_SCRAPE => PipelineEvent::SCRAPE_REQUESTED,
            PipelineJob::TYPE_CONVERT => PipelineEvent::FILE_DISCOVERED,
            PipelineJob::TYPE_INGEST => in_array($metadata['source_event_type'] ?? null, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)
                ? (string) $metadata['source_event_type']
                : PipelineEvent::FILE_CONVERTED,
            default => null,
        };
    }

    private function recoveryEvent(PipelineJob $job, string $scope, ?string $scopeId, int $retryCount): array
    {
        return [
            'event_id' => 'recovery_' . Str::uuid()->toString(),
            'event' => 'job.recovery_requested',
            'scope' => $scope,
            'scope_id' => $scopeId,
            'task_id' => $job->task_id,
            'job_id' => $job->job_id,
            'retry_count' => $retryCount,
            'idempotency_key' => $this->idempotencyKey($job),
            'at' => now()->toIso8601String(),
        ];
    }

    private function taskRecoveryMetadata(PipelineTask $task, array $event): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $metadata['last_recovery_event'] = $event;
        $metadata['recovery_events'] = array_merge(
            is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
            [$event],
        );

        return $metadata;
    }

    private function idempotencyKey(PipelineJob $job): string
    {
        return hash('sha256', implode('|', [
            $job->task_id,
            $job->job_id,
            $job->content_hash,
            $job->local_path,
            $job->source_url,
        ]));
    }

    private function emptyResult(string $scope, ?string $scopeId = null): array
    {
        return [
            'scope' => $scope,
            'scopeId' => $scopeId,
            'attempted' => 0,
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'jobs' => [],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
