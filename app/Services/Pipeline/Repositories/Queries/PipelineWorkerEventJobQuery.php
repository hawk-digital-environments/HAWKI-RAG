<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories\Queries;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineWorkerEventJobQuery
{
    public function lockByJobId(string $jobId): ?PipelineJob
    {
        return PipelineJob::query()
            ->where('job_id', $jobId)
            ->lockForUpdate()
            ->first();
    }
}
