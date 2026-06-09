<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineStageClaimService
{
    public function __construct(
        private PipelineJobRepository $jobs,
        private PipelineStageStateRepository $stageStates,
        private PipelineTransactionRepository $transactions,
        private PipelineStageAttributeNormalizer $attributes,
        private PipelineStageRollupService $rollups,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $requiredCompletedStages
     */
    public function claim(
        string $jobId,
        string $stage,
        array $attributes = [],
        array $requiredCompletedStages = [],
        bool $force = false,
    ): ?PipelineStageState {
        return $this->transactions->run(function () use ($jobId, $stage, $attributes, $requiredCompletedStages, $force): ?PipelineStageState {
            $transitionedAt = $this->now();
            $job = $this->jobs->firstOrCreateClaimJob($jobId, $stage, $transitionedAt);

            foreach ($requiredCompletedStages as $requiredStage) {
                $required = $this->stageStates->lockForJobStage($jobId, $requiredStage);

                if (! $required || $required->status !== PipelineJob::STATUS_COMPLETED) {
                    return null;
                }
            }

            $state = $this->stageStates->lockForJobStage($jobId, $stage);

            if ($state && ! $force && in_array($state->status, $this->attributes->nonClaimableStatuses(), true)) {
                return null;
            }

            $state = $this->stageStates->saveForJob(
                $state ?? $this->stageStates->newForJobStage($jobId, $stage),
                $job,
                $this->attributes->stageAttributes(array_merge($attributes, [
                    'status' => $attributes['status'] ?? PipelineJob::STATUS_RUNNING,
                    'started_at' => $attributes['started_at'] ?? $transitionedAt,
                ])),
                $this->attributes->startedStatuses(),
                $transitionedAt,
            );

            $this->rollups->refresh($job, $stage, $attributes);

            return $state;
        });
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
