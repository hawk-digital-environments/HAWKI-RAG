<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineOwnershipRepository
{
    /** Durable legacy task/job/source agreement. No identity-prefix inference. */
    public function owns(string $field, string $identity): bool
    {
        $jobs = PipelineJob::query()->when($field === 'workflow_id', fn ($q) => $q->where('temporal_workflow_id', $identity))
            ->when($field !== 'workflow_id', fn ($q) => $q->where($field, $identity))->get();
        if ($jobs->isEmpty()) {
            return false;
        }
        foreach ($jobs as $job) {
            $task = PipelineTask::query()->where('task_id', $job->task_id)->first();
            $source = IngestionSource::query()->where('source_id', $job->source_id)->first();
            if (! $task || ! $source || $source->dataset_id !== $task->dataset_id
                || isset($job->metadata['url_ingestion_operation_id'])
                || isset($source->metadata['url_ingestion_operation_id'])
                || ($source->metadata['owner'] ?? null) === 'url') {
                return false;
            }
        }

        return true;
    }

    public function exists(string $field, string $identity): bool
    {
        if ($field === 'task_id' && PipelineTask::query()->where('task_id', $identity)->exists()) {
            return true;
        }

        return PipelineJob::query()->where($field === 'workflow_id' ? 'temporal_workflow_id' : $field, $identity)->exists();
    }

    /** All candidates are authorized and ordered before the global limit. */
    public function tasks(): array
    {
        return PipelineTask::query()->where(fn ($q) => $q->whereNull('metadata->history_hidden')->orWhere('metadata->history_hidden', false))
            ->get()->map(fn ($task): array => ['task_id' => $task->task_id, 'dataset_id' => $task->dataset_id])->all();
    }

    public function jobs(?string $taskId = null, ?string $datasetId = null, bool $recoverableOnly = false): array
    {
        return PipelineJob::query()->with('task')->when($recoverableOnly, fn ($q) => $q->where(fn ($status) => $status->where('status', 'failed')->orWhereIn('metadata->pending_recovery->state', ['reserved', 'dispatching', 'uncertain'])))
            ->when($taskId, fn ($q) => $q->where('task_id', $taskId))
            ->when($datasetId, fn ($q) => $q->whereHas('task', fn ($t) => $t->where('dataset_id', $datasetId)))
            ->get()->filter(fn ($job): bool => $job->task !== null)
            ->map(fn ($job): array => ['job_id' => $job->job_id, 'task_id' => $job->task_id, 'dataset_id' => $job->task->dataset_id])->values()->all();
    }
}
