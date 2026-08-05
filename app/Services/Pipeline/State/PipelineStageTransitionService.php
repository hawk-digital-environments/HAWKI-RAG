<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineStageTransitionService
{
    public function __construct(
        private PipelineJobCreationRepository $jobCreation,
        private PipelineStageStateRepository $stageStates,
        private PipelineStageAttributeNormalizer $attributes,
        private PipelineStageRollupService $rollups,
        private ClockInterface $clock = new Clock,
    ) {}

    public function ensureJob(string $jobId, array $attributes = []): ?PipelineJob
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return $this->jobCreation->ensureStateJob(
            $jobId,
            $this->attributes->jobAttributes($attributes),
            $this->now(),
            PipelineJob::STATUS_PENDING,
        );
    }

    public function start(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->update($jobId, $stage, array_merge($attributes, [
            'status' => $attributes['status'] ?? PipelineJob::STATUS_RUNNING,
            'started_at' => $attributes['started_at'] ?? $this->now(),
            'completed_at' => null,
            'failed_at' => null,
        ]));
    }

    public function complete(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->update($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
            'failed_at' => null,
        ]));
    }

    public function skip(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->update($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_SKIPPED,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
            'failed_at' => null,
        ]));
    }

    public function partial(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->update($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_PARTIAL,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function fail(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->update($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_FAILED,
            'failed_at' => $attributes['failed_at'] ?? $this->now(),
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function update(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $job = $this->ensureJob($jobId, $attributes);
        if (! $job) {
            return null;
        }

        $state = $this->stageStates->upsertForJob(
            $job,
            $jobId,
            $stage,
            $this->attributes->stageAttributes($attributes),
            $this->attributes->startedStatuses(),
            $this->now(),
        );

        $this->rollups->refresh($job, $stage, $attributes);

        return $state;
    }

    public function incrementCounts(string $jobId, string $stage, array $deltas, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $existing = $this->stageStates->findForJobStage($jobId, $stage);

        $counts = is_array($existing?->counts) ? $existing->counts : [];
        foreach ($deltas as $key => $delta) {
            $counts[$key] = max(0, (int) ($counts[$key] ?? 0) + (int) $delta);
        }

        return $this->update($jobId, $stage, array_merge($attributes, [
            'counts' => $counts,
        ]));
    }

    private function tablesAvailable(): bool
    {
        return $this->stageStates->tablesAvailable();
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
