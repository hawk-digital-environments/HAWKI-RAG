<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskStatusService
{
    public function __construct(private ClockInterface $clock = new Clock())
    {
    }

    /**
     * @return list<string>
     */
    public function activeJobStatuses(): array
    {
        return PipelineJob::ACTIVE_STATUSES;
    }

    /**
     * @param  array<string, int>  $counters
     * @return array{status: string, finished_at: Carbon|null}
     */
    public function resolve(PipelineTask $task, array $counters, bool $hasJobs): array
    {
        if (! $hasJobs) {
            return [
                'status' => $task->status === PipelineTask::STATUS_RUNNING
                    ? PipelineTask::STATUS_RUNNING
                    : PipelineTask::STATUS_PENDING,
                'finished_at' => null,
            ];
        }

        if ($this->hasActiveJobs($counters)) {
            return [
                'status' => PipelineTask::STATUS_RUNNING,
                'finished_at' => null,
            ];
        }

        if (($counters['failed'] ?? 0) > 0) {
            return [
                'status' => PipelineTask::STATUS_FAILED,
                'finished_at' => $task->finished_at ?? $this->now(),
            ];
        }

        return [
            'status' => PipelineTask::STATUS_COMPLETED,
            'finished_at' => $task->finished_at ?? $this->now(),
        ];
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function hasActiveJobs(array $counters): bool
    {
        return ($counters['queued'] ?? 0) > 0 || ($counters['jobs_running'] ?? 0) > 0;
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
