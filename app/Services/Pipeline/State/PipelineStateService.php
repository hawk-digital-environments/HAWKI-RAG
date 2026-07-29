<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Values\PipelineStage;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStateService
{
    public const STAGE_SCRAPE = PipelineStage::Scrape->value;

    public const STAGE_CONVERT = PipelineStage::Convert->value;

    public const STAGE_INGEST = PipelineStage::Ingest->value;

    public function __construct(
        private readonly ActivePipelineJobsQuery $jobs,
        private readonly PipelineStageStateRepository $stageStates,
        private readonly PipelineStageAttributeNormalizer $attributes,
        private readonly PipelineStageClaimService $claims,
        private readonly PipelineStageStatusPayloadBuilder $payloads,
        private readonly PipelineStageTransitionService $transitions,
    ) {}

    public function ensureJob(string $jobId, array $attributes = []): ?PipelineJob
    {
        return $this->transitions->ensureJob($jobId, $attributes);
    }

    public function startStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->start($jobId, $stage, $attributes);
    }

    public function completeStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->complete($jobId, $stage, $attributes);
    }

    public function skipStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->skip($jobId, $stage, $attributes);
    }

    public function partialStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->partial($jobId, $stage, $attributes);
    }

    public function failStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->fail($jobId, $stage, $attributes);
    }

    public function updateStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->update($jobId, $stage, $attributes);
    }

    public function incrementStageCounts(string $jobId, string $stage, array $deltas, array $attributes = []): ?PipelineStageState
    {
        return $this->transitions->incrementCounts($jobId, $stage, $deltas, $attributes);
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
}
