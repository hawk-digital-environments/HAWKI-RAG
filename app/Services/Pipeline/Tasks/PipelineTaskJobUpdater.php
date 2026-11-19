<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Singleton]
readonly class PipelineTaskJobUpdater
{
    public function __construct(
        private PipelineTaskInputNormalizer $input,
        private PipelineTaskRepository $taskRepository,
        private ActivePipelineJobsQuery $jobs,
        private PipelineJobCreationRepository $jobCreation,
        private PipelineTaskStatusRefresher $refresher,
        private Repository $config,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function upsertJob(string $taskId, array $input): PipelineJob
    {
        // The drain endpoint has no editable metadata keys. Ownership and
        // recovery evidence are retained exactly as stored by legacy authority.
        if ($this->config->get('config.pipeline_execution_backend', 'python') === 'python'
            && ! empty($input['metadata'])) {
            throw new ConflictHttpException('Legacy drain updates cannot modify job metadata.');
        }
        $task = $this->taskRepository->findByTaskIdOrFail($taskId);
        $jobId = $this->input->jobId($input);
        $status = $this->input->jobStatus($input['status'] ?? null);
        $existing = $this->jobs->findByJobId($jobId);
        $metadata = array_merge(
            is_array($existing?->metadata) ? $existing->metadata : [],
            is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
        );

        $job = $this->jobCreation->upsertForTask(
            $jobId,
            $task,
            [
                'parent_job_id' => $this->input->nullableString($input['parent_job_id'] ?? $input['parentJobId'] ?? null) ?? $existing?->parent_job_id,
                'job_type' => $this->input->nullableString($input['job_type'] ?? $input['jobType'] ?? null) ?? $existing?->job_type,
                'source_url' => $this->input->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null) ?? $existing?->source_url,
                'local_path' => $this->input->nullableString($input['local_path'] ?? $input['localPath'] ?? null) ?? $existing?->local_path,
                'content_hash' => $this->input->nullableString($input['content_hash'] ?? $input['contentHash'] ?? null) ?? $existing?->content_hash,
                'status' => $status,
                'error_message' => $this->input->nullableString($input['error_message'] ?? $input['errorMessage'] ?? null),
                'started_at' => $this->input->date($input['started_at'] ?? $input['startedAt'] ?? null)
                    ?? $existing?->started_at
                    ?? (in_array($status, [PipelineJob::STATUS_RUNNING, PipelineJob::STATUS_QUEUED], true) ? $this->now() : null),
                'finished_at' => $this->input->isTerminalStatus($status) ? $this->now() : null,
                'metadata' => $metadata,
            ],
            existingOnly: $this->config->get('config.pipeline_execution_backend', 'python') === 'python',
        );

        $this->refresher->recalculate($task);

        return $job->refresh();
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
