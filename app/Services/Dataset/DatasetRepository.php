<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

#[Singleton]
readonly class DatasetRepository
{
    /**
     * @return Collection<int, Dataset>
     */
    public function recentWithTasks(int $limit): Collection
    {
        return Dataset::query()
            ->where('status', Dataset::STATUS_ACTIVE)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pipeline_tasks')
                    ->whereColumn('pipeline_tasks.dataset_id', 'datasets.dataset_id');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findByDatasetId(string $datasetId): ?Dataset
    {
        return Dataset::query()
            ->where('dataset_id', $datasetId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreate(string $datasetId, array $attributes): Dataset
    {
        $dataset = Dataset::query()->where('dataset_id', $datasetId)->lockForUpdate()->first();
        if ($dataset) {
            if ($dataset->status === Dataset::STATUS_DELETED) {
                $dataset->forceFill(['status' => Dataset::STATUS_ACTIVE])->save();
            }

            return $dataset;
        }

        return Dataset::query()->create(['dataset_id' => $datasetId, ...$attributes]);
    }

    public function completeDeletion(Dataset $dataset): bool
    {
        // Retain the tombstone until a later explicit creation or upload reuses it.
        return (bool) Dataset::query()->whereKey($dataset->getKey())
            ->where('status', Dataset::STATUS_ARCHIVED)->update(['status' => Dataset::STATUS_DELETED]);
    }

    /** @return LazyCollection<int, Dataset> */
    public function pendingDeletions(): LazyCollection
    {
        return Dataset::query()->where('status', Dataset::STATUS_ARCHIVED)->lazyById();
    }

    /** Caller commits this short transaction before invoking cleanup or Temporal. */
    public function archiveForDeletion(string $datasetId): ?Dataset
    {
        $dataset = Dataset::query()->where('dataset_id', $datasetId)->lockForUpdate()->first();
        if ($dataset && $dataset->status !== Dataset::STATUS_DELETED) {
            $dataset->forceFill(['status' => Dataset::STATUS_ARCHIVED])->save();
        }

        return $dataset;
    }

    /**
     * @return Collection<int, IngestionSource>
     */
    public function unverifiedSources(Dataset $dataset): Collection
    {
        return IngestionSource::query()->where('dataset_id', $dataset->dataset_id)->get()
            ->filter(fn (IngestionSource $source): bool => $source->index_status !== IngestionSource::STATUS_DELETED
                || ($source->metadata['managed_deletion']['status'] ?? null) !== IngestionSource::STATUS_DELETED
                || empty($source->metadata['managed_deletion']['operation_id'])
                || $source->temporal_schedule_id !== null
                || collect($source->metadata['managed_writers'] ?? [])->contains(fn ($writer): bool => ($writer['status'] ?? null) !== 'released'));
    }

    public function documentCount(Dataset $dataset): int
    {
        return ManagedDocument::query()
            ->where('dataset_id', $dataset->dataset_id)
            ->whereNull('deleted_at')
            ->count();
    }

    public function taskCount(Dataset $dataset): int
    {
        return PipelineTask::query()->where('dataset_id', $dataset->dataset_id)->count();
    }

    /**
     * @return Collection<int, PipelineTask>
     */
    public function recentTasks(Dataset $dataset, int $limit = 100): Collection
    {
        return PipelineTask::query()
            ->where('dataset_id', $dataset->dataset_id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function recentIngestionJobs(Dataset $dataset, int $limit = 100): Collection
    {
        return $this->ingestionJobQuery($dataset)
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function lastTerminalIngestionJob(Dataset $dataset): ?PipelineJob
    {
        return $this->ingestionJobQuery($dataset)
            ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_FAILED, PipelineJob::STATUS_SKIPPED])
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function ingestionJobQuery(Dataset $dataset)
    {
        return PipelineJob::query()
            ->whereIn('task_id', PipelineTask::query()
                ->select('task_id')
                ->where('dataset_id', $dataset->dataset_id))
            ->where('job_type', PipelineJob::TYPE_INGEST);
    }
}
