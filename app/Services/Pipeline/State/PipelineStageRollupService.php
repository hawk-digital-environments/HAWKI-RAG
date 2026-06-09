<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineStageRollupService
{
    public function __construct(
        private PipelineJobRepository $jobs,
        private PipelineStageStateRepository $stageStates,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function refresh(PipelineJob $job, string $currentStage, array $attributes): void
    {
        $stages = $this->stageStates->forPipelineJob($job);

        $statuses = $stages->pluck('status')->all();
        $counts = $this->counts($stages);
        $status = $this->overallStatus($statuses);

        $this->jobs->updateStageRollup(
            $job,
            $currentStage,
            $status,
            $counts,
            $status === PipelineJob::STATUS_RUNNING ? null : $this->latestCompletedAt($stages),
            $attributes,
        );
    }

    /**
     * @param list<string> $statuses
     */
    public function overallStatus(array $statuses): string
    {
        if ($statuses === []) {
            return PipelineJob::STATUS_PENDING;
        }
        if (array_intersect($statuses, [PipelineJob::STATUS_RUNNING, 'processing', 'received'])) {
            return PipelineJob::STATUS_RUNNING;
        }
        if (in_array(PipelineJob::STATUS_FAILED, $statuses, true)) {
            return in_array(PipelineJob::STATUS_COMPLETED, $statuses, true) ? PipelineJob::STATUS_PARTIAL : PipelineJob::STATUS_FAILED;
        }
        if (in_array(PipelineJob::STATUS_PARTIAL, $statuses, true)) {
            return PipelineJob::STATUS_PARTIAL;
        }
        if (count(array_unique($statuses)) === 1 && $statuses[0] === PipelineJob::STATUS_SKIPPED) {
            return PipelineJob::STATUS_SKIPPED;
        }
        if (count($statuses) < 3) {
            return PipelineJob::STATUS_PARTIAL;
        }

        return PipelineJob::STATUS_COMPLETED;
    }

    /**
     * @param iterable<PipelineStageState> $stages
     * @return array{total:int,processed:int,failed:int,skipped:int}
     */
    public function counts(iterable $stages): array
    {
        $counts = ['total' => 0, 'processed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($stages as $stage) {
            $stageCounts = is_array($stage->counts) ? $stage->counts : [];
            $counts['total'] += (int) ($stageCounts['total'] ?? $stageCounts['sourceFiles'] ?? $stageCounts['totalPages'] ?? 0);
            $counts['processed'] += (int) ($stageCounts['processed'] ?? $stageCounts['convertedFiles'] ?? $stageCounts['completed'] ?? $stageCounts['pagesCrawled'] ?? 0);
            $counts['failed'] += (int) ($stageCounts['failed'] ?? $stageCounts['failedFiles'] ?? $stageCounts['failedUrls'] ?? 0);
            $counts['skipped'] += (int) ($stageCounts['skipped'] ?? $stageCounts['skippedFiles'] ?? 0);
        }

        return $counts;
    }

    /**
     * @param iterable<PipelineStageState> $stages
     */
    public function latestCompletedAt(iterable $stages): ?Carbon
    {
        $latest = null;
        foreach ($stages as $stage) {
            if (! $stage->completed_at) {
                continue;
            }
            if (! $latest || $stage->completed_at->gt($latest)) {
                $latest = $stage->completed_at;
            }
        }

        return $latest;
    }
}
