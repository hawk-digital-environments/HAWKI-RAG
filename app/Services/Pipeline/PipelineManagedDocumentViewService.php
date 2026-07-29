<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\ManagedDocumentPipelineSummaryService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineManagedDocumentViewService
{
    public function __construct(
        private ManagedDocumentPipelineSummaryService $documents,
        private IngestionSourceRepository $sources,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managedDocumentsForTask(PipelineTask $task): array
    {
        return $this->documents->summariesForTask($task);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managedDocumentsForJob(PipelineJob $job): array
    {
        return $this->documents->summariesForJob($job);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourcesForTask(PipelineTask $task): array
    {
        if (! $task->relationLoaded('jobs')) {
            return [];
        }

        return $task->jobs
            ->filter(fn (PipelineJob $job): bool => is_string($job->source_id) && trim($job->source_id) !== '')
            ->map(fn (PipelineJob $job): ?array => $this->source(
                is_string($job->source_id) ? $job->source_id : null,
                $job->source_url,
                $task->dataset_id,
                $task->task_id,
            ))
            ->filter()
            ->unique('source_id')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function source(
        ?string $sourceId,
        ?string $fallbackSourceUrl = null,
        ?string $fallbackDatasetId = null,
        ?string $fallbackTaskId = null,
    ): ?array {
        $sourceId = $this->stringValue($sourceId);
        $managedDocuments = $this->documents->summariesForSourceId($sourceId);
        $source = $sourceId === null ? null : $this->sources->findBySourceId($sourceId);

        if (! $source instanceof IngestionSource && $managedDocuments === [] && $fallbackSourceUrl === null) {
            return null;
        }

        return [
            'source_id' => $source?->source_id ?? $sourceId,
            'source_url' => $source?->source_url ?? $fallbackSourceUrl,
            'dataset_id' => $source?->dataset_id ?? $fallbackDatasetId,
            'task_id' => $source?->task_id ?? $fallbackTaskId,
            'index_status' => $source?->index_status,
            'document_version' => $source?->document_version,
            'refresh_cadence' => $source?->refresh_cadence,
            'temporal_workflow_id' => $source?->temporal_workflow_id,
            'temporal_schedule_id' => $source?->temporal_schedule_id,
            'raw_storage_path' => $source?->raw_storage_path,
            'markdown_storage_path' => $source?->markdown_storage_path,
            'ready_at' => $source?->ready_at?->toIso8601String(),
            'metadata' => is_array($source?->metadata) ? $source->metadata : [],
            'managed_document_count' => count($managedDocuments),
            'managed_documents' => $managedDocuments,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
