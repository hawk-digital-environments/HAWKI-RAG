<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentWriterRepository
{
    public function lockJob(string $id): ?PipelineJob
    {
        return PipelineJob::query()->where('job_id', $id)->lockForUpdate()->first();
    }

    public function job(string $id): ?PipelineJob
    {
        return PipelineJob::query()->where('job_id', $id)->first();
    }

    public function document(string $id): ?ManagedDocument
    {
        return ManagedDocument::query()->find($id);
    }

    public function lockDocument(string $id): ?ManagedDocument
    {
        return ManagedDocument::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function adoptRun(PipelineJob $job, string $runId): void
    {
        $job->forceFill(['temporal_run_id' => $runId])->save();
    }

    public function latestJob(string $sourceId): ?PipelineJob
    {
        return PipelineJob::query()->where('source_id', $sourceId)->orderByDesc('id')->lockForUpdate()->first();
    }
}
