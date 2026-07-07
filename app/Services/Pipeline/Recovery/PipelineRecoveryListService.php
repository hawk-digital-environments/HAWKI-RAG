<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryListService
{
    public function __construct(
        private PipelineRecoveryInputNormalizer $input,
        private FailedPipelineJobsQuery $failedJobs,
        private PipelineRecoveryFailedJobPresenter $presenter,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function failedJobs(array $filters = []): array
    {
        $filters = $this->input->filters($filters);

        return $this->failedJobs
            ->forRecoveryList($filters['task_id'], $filters['heap_id'], $filters['limit'])
            ->map(fn (PipelineJob $job): array => $this->presenter->present($job))
            ->all();
    }
}
