<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
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
        $jobs = $task->jobs;

        return array_merge($this->summary($task, $activeJobs, $defaultCounters), [
            'jobs' => $jobs
                ->map(fn (PipelineJob $job) => $this->job($job))
                ->all(),
            'stages' => $this->stages($task),
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
            'stages' => $this->stages($task),
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stages(PipelineTask $task): array
    {
        if (! $task->relationLoaded('jobs')) {
            return [];
        }

        $job = $task->jobs
            ->first(fn (PipelineJob $candidate): bool => $candidate->job_type === PipelineJob::TYPE_INGEST);

        if (! $job instanceof PipelineJob) {
            return [];
        }

        if (! $this->isUploadedFileTask($task)) {
            return $this->trackedStages($job);
        }

        return $this->uploadedFileStages($job);
    }

    private function isUploadedFileTask(PipelineTask $task): bool
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        return ($metadata['request']['mode'] ?? null) === 'uploaded_file_convert_ingest';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function uploadedFileStages(PipelineJob $job): array
    {
        $phase = (string) ($job->current_stage ?? '');
        $details = is_array($job->metadata) ? $job->metadata : [];
        $convertStatus = $this->uploadedConvertStatus($job, $phase, $details);
        $ingestStatus = $this->uploadedIngestStatus($job, $phase);
        $tracked = $this->trackedStages($job);

        return [
            'scrape' => [
                'status' => 'n/a',
                'message' => 'Mode not available for uploaded files.',
                'counts' => [],
            ],
            'convert' => [
                'status' => $tracked['convert']['status'] ?? $convertStatus,
                'message' => $this->stageMessage($convertStatus, [
                    'processing' => 'Converting uploaded file.',
                    'running' => 'Converting uploaded file.',
                    'completed' => 'Conversion finished.',
                    'failed' => $job->error_message ?: ($details['error_details'] ?? 'Conversion failed.'),
                    'queued' => 'Waiting for converter.',
                ]),
                'startedAt' => $tracked['convert']['startedAt'] ?? $this->dateValue($job->started_at),
                'completedAt' => $tracked['convert']['completedAt'] ?? ($convertStatus === PipelineJob::STATUS_COMPLETED ? $this->dateValue($job->updated_at) : null),
                'counts' => array_merge($this->uploadedConvertCounts($convertStatus, $details), $tracked['convert']['counts'] ?? []),
                'errors' => $tracked['convert']['errors'] ?? ($convertStatus === PipelineJob::STATUS_FAILED ? array_values(array_filter([
                    $job->error_message ?: ($details['error_details'] ?? null),
                ])) : []),
            ],
            'ingest' => [
                'status' => $tracked['ingest']['status'] ?? $ingestStatus,
                'message' => $this->stageMessage($ingestStatus, [
                    'queued' => 'Waiting for converter to finish.',
                    'processing' => 'Ingestion processing.',
                    'running' => 'Ingestion processing.',
                    'completed' => 'Ingestion finished.',
                    'failed' => $job->error_message ?: ($details['error_details'] ?? 'Ingestion failed.'),
                ]),
                'startedAt' => $tracked['ingest']['startedAt'] ?? (in_array($ingestStatus, ['processing', 'running', PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_FAILED], true)
                    ? $this->dateValue($job->updated_at)
                    : null),
                'completedAt' => $tracked['ingest']['completedAt'] ?? ($ingestStatus === PipelineJob::STATUS_COMPLETED ? $this->dateValue($job->finished_at) : null),
                'counts' => array_merge($this->uploadedIngestCounts($details), $tracked['ingest']['counts'] ?? []),
                'errors' => $tracked['ingest']['errors'] ?? ($ingestStatus === PipelineJob::STATUS_FAILED ? array_values(array_filter([
                    $job->error_message ?: ($details['error_details'] ?? null),
                ])) : []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function uploadedConvertStatus(PipelineJob $job, string $phase, array $details): string
    {
        if ($job->status === PipelineJob::STATUS_FAILED && $phase === 'inspect_and_convert_files') {
            return PipelineJob::STATUS_FAILED;
        }

        if ($job->status === PipelineJob::STATUS_COMPLETED
            || in_array($phase, ['ingest', 'ingest_markdown_files', 'mark_source_ready'], true)
            || ($phase === 'inspect_and_convert_files' && ($details['status'] ?? null) === 'success')) {
            return PipelineJob::STATUS_COMPLETED;
        }

        if (in_array($phase, ['convert', 'temporal.workflow_starting', 'temporal.workflow_started', 'scrape_source', 'inspect_and_convert_files'], true)) {
            return 'processing';
        }

        if ($job->status === PipelineJob::STATUS_FAILED) {
            return PipelineJob::STATUS_FAILED;
        }

        return PipelineJob::STATUS_QUEUED;
    }

    private function uploadedIngestStatus(PipelineJob $job, string $phase): string
    {
        if ($job->status === PipelineJob::STATUS_COMPLETED || $phase === 'mark_source_ready') {
            return PipelineJob::STATUS_COMPLETED;
        }

        if ($job->status === PipelineJob::STATUS_FAILED && in_array($phase, ['ingest_markdown_files', 'mark_source_ready'], true)) {
            return PipelineJob::STATUS_FAILED;
        }

        if (in_array($phase, ['ingest', 'ingest_markdown_files'], true)) {
            return 'processing';
        }

        return PipelineJob::STATUS_QUEUED;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, int>
     */
    private function uploadedConvertCounts(string $status, array $details): array
    {
        $convertedFiles = is_array($details['converted_files'] ?? null)
            ? count($details['converted_files'])
            : ($status === PipelineJob::STATUS_COMPLETED ? 1 : 0);
        $sourceFiles = max(1, (int) ($details['files_found'] ?? $convertedFiles));

        return [
            'sourceFiles' => $sourceFiles,
            'convertedFiles' => $convertedFiles,
        ];
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, int>
     */
    private function uploadedIngestCounts(array $details): array
    {
        return [
            'total' => (int) ($details['documents_indexed'] ?? $details['document_count'] ?? 0),
            'completed' => (int) ($details['documents_indexed'] ?? 0),
            'chunks' => (int) ($details['chunks_indexed'] ?? 0),
            'vectors' => (int) ($details['vectors_upserted'] ?? 0),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function trackedStages(PipelineJob $job): array
    {
        if (! $job->relationLoaded('stages')) {
            return [];
        }

        return $job->stages
            ->mapWithKeys(fn (PipelineStageState $stage): array => [
                $stage->stage => [
                    'status' => $stage->status,
                    'startedAt' => $this->dateValue($stage->started_at),
                    'completedAt' => $this->dateValue($stage->completed_at),
                    'counts' => $this->stageCounts((string) $stage->stage, is_array($stage->counts) ? $stage->counts : []),
                    'errors' => $stage->errors ?? [],
                ],
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $counts
     * @return array<string, int>
     */
    private function stageCounts(string $stage, array $counts): array
    {
        if ($stage === 'scrape') {
            $pagesCrawled = (int) ($counts['pagesCrawled'] ?? $counts['processed'] ?? $counts['completed'] ?? 0);
            $totalPages = (int) ($counts['totalPages'] ?? $counts['total'] ?? $pagesCrawled);

            return array_merge($counts, [
                'pagesCrawled' => $pagesCrawled,
                'totalPages' => $totalPages,
            ]);
        }

        if ($stage === 'convert') {
            $convertedFiles = (int) ($counts['convertedFiles'] ?? $counts['processed'] ?? $counts['completed'] ?? 0);
            $sourceFiles = (int) ($counts['sourceFiles'] ?? $counts['total'] ?? $convertedFiles);

            return array_merge($counts, [
                'convertedFiles' => $convertedFiles,
                'sourceFiles' => $sourceFiles,
            ]);
        }

        if ($stage === 'ingest') {
            $completed = (int) ($counts['completed'] ?? $counts['processed'] ?? 0);
            $total = (int) ($counts['total'] ?? $completed);

            return array_merge($counts, [
                'completed' => $completed,
                'total' => $total,
            ]);
        }

        return $counts;
    }

    /**
     * @param array<string, string> $messages
     */
    private function stageMessage(string $status, array $messages): string
    {
        return $messages[$status] ?? '';
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value ? (string) $value : null;
    }
}
