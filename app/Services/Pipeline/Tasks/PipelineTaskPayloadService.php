<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineManagedDocumentViewService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskPayloadService
{
    public function __construct(
        private PipelineManagedDocumentViewService $documents,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param  array<string, int>  $defaultCounters
     * @return array<string, mixed>
     */
    public function detail(PipelineTask $task, int $activeJobs, array $defaultCounters): array
    {
        $jobs = $task->jobs;
        $managedDocuments = $this->documents->managedDocumentsForTask($task);
        $sources = $this->documents->sourcesForTask($task);
        $sourceMap = collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['source_id'] ?? null))
            ->mapWithKeys(fn (array $source): array => [(string) $source['source_id'] => $source])
            ->all();

        return array_merge($this->summary($task, $activeJobs, $defaultCounters), [
            'managed_documents' => $managedDocuments,
            'managed_document_count' => count($managedDocuments),
            'sources' => $sources,
            'jobs' => $jobs
                ->map(fn (PipelineJob $job) => $this->job($job, $this->sourceForJob($job, $sourceMap)))
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
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'status' => $task->status,
            'started_at' => $this->dateValue($task->started_at),
            'finished_at' => $this->dateValue($task->finished_at),
            'counters' => $task->counters ?? $defaultCounters,
            'metadata' => $task->metadata ?? [],
            'active_jobs' => $activeJobs,
            'updated_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'stages' => $this->stages($task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function job(PipelineJob $job, ?array $source = null): array
    {
        $managedDocuments = $this->documents->managedDocumentsForJob($job);

        return [
            'job_id' => $job->job_id,
            'task_id' => $job->task_id,
            'parent_job_id' => $job->parent_job_id,
            'job_type' => $job->job_type,
            'source_id' => $job->source_id,
            'source_url' => $job->source_url,
            'local_path' => $job->local_path,
            'content_hash' => $job->content_hash,
            'temporal_workflow_id' => $job->temporal_workflow_id,
            'temporal_run_id' => $job->temporal_run_id,
            'temporal_schedule_id' => $job->temporal_schedule_id,
            'index_status' => $job->index_status,
            'status' => $job->status,
            'error_message' => $job->error_message,
            'started_at' => $this->dateValue($job->started_at),
            'finished_at' => $this->dateValue($job->finished_at),
            'metadata' => $job->metadata ?? [],
            'managed_documents' => $managedDocuments,
            'managed_document_count' => count($managedDocuments),
            'source' => $source ?? $this->documents->source(
                is_string($job->source_id) ? $job->source_id : null,
                $job->source_url,
                null,
                $job->task_id,
            ),
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
                    'event_type' => $this->nullableString($event['event_type'] ?? $event['eventType'] ?? $event['event'] ?? null) ?? 'job.status',
                    'event_id' => $this->nullableString($event['event_id'] ?? $event['eventId'] ?? null),
                    'task_id' => $job->task_id,
                    'job_id' => $job->job_id,
                    'job_type' => $job->job_type,
                    'status' => $this->nullableString($event['status'] ?? null) ?? $job->status,
                    'source_url' => $job->source_url,
                    'local_path' => $job->local_path,
                    'error_message' => $job->error_message,
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
     * @param array<string, array<string, mixed>> $sourceMap
     * @return array<string, mixed>|null
     */
    private function sourceForJob(PipelineJob $job, array $sourceMap): ?array
    {
        $sourceId = is_string($job->source_id) ? trim($job->source_id) : '';
        if ($sourceId === '') {
            return null;
        }

        return $sourceMap[$sourceId] ?? null;
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
            return $this->trackedStages($job, $this->scrapePageLimit($task, $job));
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
                'started_at' => $tracked['convert']['started_at'] ?? $this->dateValue($job->started_at),
                'completed_at' => $tracked['convert']['completed_at'] ?? ($convertStatus === PipelineJob::STATUS_COMPLETED ? $this->dateValue($job->updated_at) : null),
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
                'started_at' => $tracked['ingest']['started_at'] ?? (in_array($ingestStatus, ['processing', 'running', PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_FAILED], true)
                    ? $this->dateValue($job->updated_at)
                    : null),
                'completed_at' => $tracked['ingest']['completed_at'] ?? ($ingestStatus === PipelineJob::STATUS_COMPLETED ? $this->dateValue($job->finished_at) : null),
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
            'source_files' => $sourceFiles,
            'converted_files' => $convertedFiles,
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
    private function trackedStages(PipelineJob $job, ?int $scrapePageLimit = null): array
    {
        if (! $job->relationLoaded('stages')) {
            return [];
        }

        return $job->stages
            ->mapWithKeys(function (PipelineStageState $stage) use ($scrapePageLimit): array {
                $counts = $this->stageCounts(
                    (string) $stage->stage,
                    is_array($stage->counts) ? $stage->counts : [],
                    $scrapePageLimit,
                );

                return [
                    $stage->stage => [
                        'status' => $this->stageStatus($stage, $counts),
                        'started_at' => $this->dateValue($stage->started_at),
                        'completed_at' => $this->dateValue($stage->completed_at),
                        'counts' => $counts,
                        'errors' => $this->stageErrors($stage, $counts),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param array<string, mixed> $counts
     * @return array<string, int>
     */
    private function stageCounts(string $stage, array $counts, ?int $scrapePageLimit = null): array
    {
        if ($stage === 'scrape') {
            $pagesCrawled = (int) ($counts['pages_crawled'] ?? $counts['pagesCrawled'] ?? $counts['processed'] ?? $counts['completed'] ?? 0);
            $pageLimit = $this->positiveInt(
                $counts['page_limit'] ?? $counts['pageLimit'] ?? $counts['max_pages'] ?? $counts['maxPages'] ?? null,
            ) ?? $scrapePageLimit;
            $totalPages = $pageLimit
                ?? (int) ($counts['total_pages'] ?? $counts['totalPages'] ?? $counts['total'] ?? $pagesCrawled);

            $normalized = array_merge($counts, [
                'pages_crawled' => $pagesCrawled,
                'total_pages' => max($pagesCrawled, $totalPages),
            ]);

            if ($pageLimit !== null) {
                $normalized['page_limit'] = $pageLimit;
                $normalized['total_pages'] = max($pagesCrawled, $pageLimit);
            }

            return $normalized;
        }

        if ($stage === 'convert') {
            $convertedFiles = (int) ($counts['converted_files'] ?? $counts['convertedFiles'] ?? $counts['processed'] ?? $counts['completed'] ?? 0);
            $sourceFiles = (int) ($counts['source_files'] ?? $counts['sourceFiles'] ?? $counts['total'] ?? $convertedFiles);

            return array_merge($counts, [
                'converted_files' => $convertedFiles,
                'source_files' => $sourceFiles,
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
     * @param array<string, mixed> $counts
     */
    private function stageStatus(PipelineStageState $stage, array $counts): string
    {
        $status = (string) $stage->status;
        if ((string) $stage->stage === 'scrape'
            && $status === PipelineJob::STATUS_COMPLETED
            && $this->scrapeStoppedBeforeLimit($counts)) {
            return PipelineJob::STATUS_FAILED;
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $counts
     * @return list<mixed>
     */
    private function stageErrors(PipelineStageState $stage, array $counts): array
    {
        $errors = is_array($stage->errors) ? $stage->errors : [];
        if ((string) $stage->stage === 'scrape'
            && (string) $stage->status === PipelineJob::STATUS_COMPLETED
            && $this->scrapeStoppedBeforeLimit($counts)
            && $errors === []) {
            $errors[] = sprintf(
                'Scraper stopped at %d/%d pages before reaching the configured page limit.',
                $this->countValue($counts['pages_crawled'] ?? $counts['pagesCrawled'] ?? null),
                $this->countValue($counts['page_limit'] ?? $counts['pageLimit'] ?? null),
            );
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function scrapeStoppedBeforeLimit(array $counts): bool
    {
        $pageLimit = $this->positiveInt($counts['page_limit'] ?? $counts['pageLimit'] ?? null);
        if ($pageLimit === null) {
            return false;
        }

        return $this->countValue($counts['pages_crawled'] ?? $counts['pagesCrawled'] ?? null) < $pageLimit;
    }

    private function scrapePageLimit(PipelineTask $task, PipelineJob $job): ?int
    {
        $taskMetadata = is_array($task->metadata) ? $task->metadata : [];
        $taskRequest = is_array($taskMetadata['request'] ?? null) ? $taskMetadata['request'] : [];
        $taskRequestMetadata = is_array($taskRequest['metadata'] ?? null) ? $taskRequest['metadata'] : [];
        $jobMetadata = is_array($job->metadata) ? $job->metadata : [];
        $jobRequest = is_array($jobMetadata['request'] ?? null) ? $jobMetadata['request'] : [];
        $jobRequestMetadata = is_array($jobRequest['metadata'] ?? null) ? $jobRequest['metadata'] : [];

        foreach ([
            $taskRequestMetadata['max_pages'] ?? null,
            $taskRequestMetadata['maxPages'] ?? null,
            $jobMetadata['max_pages'] ?? null,
            $jobMetadata['maxPages'] ?? null,
            $jobRequestMetadata['max_pages'] ?? null,
            $jobRequestMetadata['maxPages'] ?? null,
        ] as $candidate) {
            $limit = $this->positiveInt($candidate);
            if ($limit !== null) {
                return $limit;
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function countValue(mixed $value): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return 0;
        }

        return max(0, (int) $value);
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
