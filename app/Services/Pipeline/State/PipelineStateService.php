<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Values\PipelineStage;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineStateService
{
    public const STAGE_SCRAPE = PipelineStage::Scrape->value;

    public const STAGE_CONVERT = PipelineStage::Convert->value;

    public const STAGE_INGEST = PipelineStage::Ingest->value;

    public function __construct(
        private readonly ActivePipelineJobsQuery $jobs,
        private readonly PipelineJobCreationRepository $jobCreation,
        private readonly PipelineStageStateRepository $stageStates,
        private readonly PipelineStageAttributeNormalizer $attributes,
        private readonly PipelineStageClaimService $claims,
        private readonly PipelineStageRollupService $rollups,
        private readonly PipelineStageStatusPayloadBuilder $payloads,
        private readonly ClockInterface $clock = new Clock(),
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

    public function startStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => $attributes['status'] ?? PipelineJob::STATUS_RUNNING,
            'started_at' => $attributes['started_at'] ?? $this->now(),
        ]));
    }

    public function completeStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function skipStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_SKIPPED,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function partialStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_PARTIAL,
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function failStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_FAILED,
            'failed_at' => $attributes['failed_at'] ?? $this->now(),
            'completed_at' => $attributes['completed_at'] ?? $this->now(),
        ]));
    }

    public function updateStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $job = $this->ensureJob($jobId, $attributes);
        if (! $job) {
            return null;
        }

        $transitionedAt = $this->now();
        $state = $this->stageStates->upsertForJob(
            $job,
            $jobId,
            $stage,
            $this->attributes->stageAttributes($attributes),
            $this->attributes->startedStatuses(),
            $transitionedAt,
        );

        $this->rollups->refresh($job, $stage, $attributes);

        return $state;
    }

    public function incrementStageCounts(string $jobId, string $stage, array $deltas, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $existing = $this->stageStates->findForJobStage($jobId, $stage);

        $counts = is_array($existing?->counts) ? $existing->counts : [];
        foreach ($deltas as $key => $delta) {
            $counts[$key] = max(0, (int) ($counts[$key] ?? 0) + (int) $delta);
        }

        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'counts' => $counts,
        ]));
    }

    public function claimStage(
        string $jobId,
        string $stage,
        array $attributes = [],
        array $requiredCompletedStages = [],
        bool $force = false
    ): ?PipelineStageState {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return $this->claims->claim($jobId, $stage, $attributes, $requiredCompletedStages, $force);
    }

    public function stageStatusValue(string $jobId, string $stage): ?string
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return $this->stageStates->statusValue($jobId, $stage);
    }

    public function isStageCompleted(string $jobId, string $stage): bool
    {
        return $this->stageStatusValue($jobId, $stage) === PipelineJob::STATUS_COMPLETED;
    }

    public function isStageClaimedOrDone(string $jobId, string $stage): bool
    {
        $status = $this->stageStatusValue($jobId, $stage);

        return $status !== null && in_array($status, $this->attributes->nonClaimableStatuses(), true);
    }

    public function status(string $jobId): ?array
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $job = $this->jobs->findWithOrderedStagesByJobId($jobId);

        if (! $job) {
            return null;
        }

        return $this->payloads->forJob($job);
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
