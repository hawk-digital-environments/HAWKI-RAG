<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryFailedJobPresenter
{
    public function __construct(
        private PipelineRecoveryPayloadService $payloads,
        private PipelineTaskRepository $tasks,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PipelineJob $job): array
    {
        $task = $job->relationLoaded('task')
            ? $job->task
            : $this->tasks->findByTaskId((string) $job->task_id);

        return $this->payloads->failedJob($job, $task);
    }
}
