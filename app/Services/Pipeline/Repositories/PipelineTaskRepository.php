<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineTaskRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): PipelineTask
    {
        return PipelineTask::query()->create($attributes);
    }

    public function markFailed(PipelineTask $task, Carbon $failedAt): PipelineTask
    {
        $task->forceFill([
            'status' => PipelineTask::STATUS_FAILED,
            'finished_at' => $failedAt,
        ])->save();

        return $task->refresh();
    }
}
