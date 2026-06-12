<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineJobCreationRepository
{
    /**
     * @param array<string, mixed> $metadata
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
            'status' => PipelineJob::STATUS_SKIPPED,
            'current_stage' => 'upload.metadata_stored',
            'index_status' => 'skipped',
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
            'finished_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
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
     * @param array<string, mixed> $metadata
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
     * @param array<string, mixed> $attributes
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
     * @param array<string, mixed> $attributes
     */
    public function upsertForTask(string $jobId, PipelineTask $task, array $attributes): PipelineJob
    {
        return PipelineJob::query()->updateOrCreate(
            ['job_id' => $jobId],
            array_merge($attributes, [
                'task_id' => $task->task_id,
            ]),
        );
    }

}
