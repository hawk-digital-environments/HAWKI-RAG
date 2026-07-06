<?php

declare(strict_types=1);

namespace App\Services\Heap\Repositories;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class HeapActivityRepository
{
    public function documentCount(Dataset $heap): int
    {
        return $this->documentQuery($heap)->count();
    }

    public function taskCount(Dataset $heap): int
    {
        return PipelineTask::query()->where('dataset_id', $heap->dataset_id)->count();
    }

    /**
     * @return Collection<int, PipelineTask>
     */
    public function recentTasks(Dataset $heap, int $limit = 100): Collection
    {
        return PipelineTask::query()
            ->where('dataset_id', $heap->dataset_id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Document>
     */
    public function recentDocuments(Dataset $heap, int $limit = 100): Collection
    {
        return $this->documentQuery($heap)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function recentIngestionJobs(Dataset $heap, int $limit = 100): Collection
    {
        return $this->ingestionJobQuery($heap)
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function lastTerminalIngestionJob(Dataset $heap): ?PipelineJob
    {
        return $this->ingestionJobQuery($heap)
            ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_FAILED, PipelineJob::STATUS_SKIPPED])
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    public function documentExternalIds(Dataset $heap): array
    {
        return $this->documentQuery($heap)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->filter()
            ->values()
            ->all();
    }

    private function ingestionJobQuery(Dataset $heap)
    {
        return PipelineJob::query()
            ->whereIn('task_id', PipelineTask::query()
                ->select('task_id')
                ->where('dataset_id', $heap->dataset_id))
            ->where('job_type', PipelineJob::TYPE_INGEST);
    }

    private function documentQuery(Dataset $heap)
    {
        return Document::query()
            ->where(function ($query) use ($heap): void {
                $query->where('dataset_id', $heap->dataset_id)
                    ->orWhere('collection', $heap->qdrant_collection);
            });
    }
}
