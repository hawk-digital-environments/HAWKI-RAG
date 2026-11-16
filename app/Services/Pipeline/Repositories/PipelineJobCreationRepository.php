<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Singleton]
readonly class PipelineJobCreationRepository
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createUploadConvertJob(
        string $jobId,
        PipelineTask $task,
        string $sourceUrl,
        PipelineStoredUpload $storedUpload,
        Carbon $startedAt,
        array $metadata,
    ): PipelineJob {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'content_hash' => $storedUpload->contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'current_stage' => 'upload.queued',
            'index_status' => 'queued',
            'started_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createUploadIngestJob(
        string $jobId,
        PipelineTask $task,
        string $sourceId,
        string $sourceUrl,
        PipelineStoredUpload $storedUpload,
        Carbon $startedAt,
        array $metadata,
    ): PipelineJob {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'source_id' => $sourceId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'content_hash' => $storedUpload->contentHash,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_starting',
            'index_status' => 'running',
            'started_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createTextIngestJob(
        string $jobId,
        PipelineTask $task,
        string $sourceId,
        string $sourceUrl,
        string $markdownPath,
        string $contentHash,
        string $workflowId,
        Carbon $startedAt,
        array $metadata,
    ): PipelineJob {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'source_id' => $sourceId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $markdownPath,
            'content_hash' => $contentHash,
            'temporal_workflow_id' => $workflowId,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_starting',
            'index_status' => 'running',
            'started_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createScrapeJob(
        string $jobId,
        PipelineTask $task,
        string $sourceUrl,
        string $contentHash,
        string $status,
        Carbon $startedAt,
        ?Carbon $finishedAt,
        array $metadata,
    ): PipelineJob {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $sourceUrl,
            'content_hash' => $contentHash,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createTemporalSourceJob(
        string $jobId,
        PipelineTask $task,
        string $sourceId,
        string $sourceUrl,
        string $contentHash,
        Carbon $startedAt,
        array $metadata,
    ): PipelineJob {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'source_id' => $sourceId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'index_status' => 'running',
            'started_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function ensureStateJob(
        string $jobId,
        array $attributes,
        Carbon $startedAt,
        string $defaultStatus,
    ): PipelineJob {
        $job = PipelineJob::query()->firstOrNew(['job_id' => $jobId]);
        $job->fill($attributes);

        if (! $job->started_at) {
            $job->started_at = $startedAt;
        }
        if (! $job->status) {
            $job->status = $defaultStatus;
        }

        $job->save();

        return $job->refresh();
    }

    public function firstOrCreateClaimJob(string $jobId, string $stage, Carbon $startedAt): PipelineJob
    {
        return PipelineJob::query()->firstOrCreate(
            ['job_id' => $jobId],
            [
                'status' => PipelineJob::STATUS_PENDING,
                'current_stage' => $stage,
                'started_at' => $startedAt,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertForTask(string $jobId, PipelineTask $task, array $attributes, bool $existingOnly = false): PipelineJob
    {
        return DB::transaction(function () use ($jobId, $task, $attributes, $existingOnly): PipelineJob {
            $job = PipelineJob::query()->where('job_id', $jobId)->lockForUpdate()->first();
            if ($job && $job->task_id !== $task->task_id) {
                throw new ConflictHttpException('A job cannot be moved to another task.');
            }
            if ($existingOnly) {
                $source = $job ? IngestionSource::query()->where('source_id', $job->source_id)->lockForUpdate()->first() : null;
                $currentTask = PipelineTask::query()->where('task_id', $task->task_id)->lockForUpdate()->first();
                if (! $job || ! $source || ! $currentTask || $source->dataset_id !== $currentTask->dataset_id
                    || isset($job->metadata['url_ingestion_operation_id'])
                    || isset($source->metadata['url_ingestion_operation_id'])
                    || ($source->metadata['owner'] ?? null) === 'url'
                    || ($attributes['metadata'] ?? []) !== ($job->metadata ?? [])) {
                    throw new ConflictHttpException('Existing legacy job ownership is required.');
                }
                $job->fill($attributes)->save();

                return $job;
            }

            $job ??= PipelineJob::query()->firstOrCreate(['job_id' => $jobId], array_merge($attributes, ['task_id' => $task->task_id]));
            // A concurrent insertion may have won after the initial lookup.
            if ($job->task_id !== $task->task_id) {
                throw new ConflictHttpException('A job cannot be moved to another task.');
            }
            $job->fill($attributes)->save();

            return $job;
        });
    }
}
