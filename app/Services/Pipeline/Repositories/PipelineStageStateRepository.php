<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineStageStateRepository
{
    public function __construct(private PipelineSchemaInspector $schema)
    {
    }

    public function tablesAvailable(): bool
    {
        return $this->schema->hasTables(['pipeline_jobs', 'pipeline_stage_states']);
    }

    public function findForJobStage(string $jobId, string $stage): ?PipelineStageState
    {
        return PipelineStageState::query()
            ->where('job_id', $jobId)
            ->where('stage', $stage)
            ->first();
    }

    public function lockForJobStage(string $jobId, string $stage): ?PipelineStageState
    {
        return PipelineStageState::query()
            ->where('job_id', $jobId)
            ->where('stage', $stage)
            ->lockForUpdate()
            ->first();
    }

    public function statusValue(string $jobId, string $stage): ?string
    {
        return PipelineStageState::query()
            ->where('job_id', $jobId)
            ->where('stage', $stage)
            ->value('status');
    }

    /**
     * @return Collection<int, PipelineStageState>
     */
    public function forPipelineJob(PipelineJob $job): Collection
    {
        return PipelineStageState::query()
            ->where('pipeline_job_id', $job->id)
            ->get();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $startedStatuses
     */
    public function upsertForJob(
        PipelineJob $job,
        string $jobId,
        string $stage,
        array $attributes,
        array $startedStatuses,
        Carbon $transitionedAt,
    ): PipelineStageState {
        $state = PipelineStageState::query()->firstOrNew([
            'job_id' => $jobId,
            'stage' => $stage,
        ]);

        return $this->saveForJob($state, $job, $attributes, $startedStatuses, $transitionedAt);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $startedStatuses
     */
    public function saveForJob(
        PipelineStageState $state,
        PipelineJob $job,
        array $attributes,
        array $startedStatuses,
        Carbon $transitionedAt,
    ): PipelineStageState {
        $state->pipeline_job_id = $job->id;
        $state->fill($attributes);

        if (!$state->started_at && in_array($state->status, $startedStatuses, true)) {
            $state->started_at = $transitionedAt;
        }

        $state->last_transition_at = $transitionedAt;
        $state->save();

        return $state->refresh();
    }

    public function newForJobStage(string $jobId, string $stage): PipelineStageState
    {
        return new PipelineStageState([
            'job_id' => $jobId,
            'stage' => $stage,
        ]);
    }
}
