<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class DatasetRepository
{
    /**
     * @return Collection<int, Dataset>
     */
    public function recentWithTasks(int $limit): Collection
    {
        return Dataset::query()
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
    public function create(array $attributes): Dataset
    {
        return Dataset::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreate(string $datasetId, array $attributes): Dataset
    {
        return Dataset::query()->firstOrCreate(['dataset_id' => $datasetId], $attributes);
    }

    public function delete(Dataset $dataset): bool
    {
        return (bool) $dataset->delete();
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
