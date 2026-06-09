<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorCompletionHandler
{
    public function __construct(
        private PipelineStateService $pipelineState,
        private ActivePipelineJobsQuery $jobs,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineStageLogger $logger,
        private ScrapeMonitorOutputPublisher $outputs,
        private ScrapeMonitorPolicy $policy,
    ) {
    }

    public function complete(array $event, ScrapeMonitorStatusSnapshot $snapshot): void
    {
        $this->pipelineState->completeStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'dataset_path' => $snapshot->datasetPath !== '' ? $snapshot->datasetPath : null,
            'counts' => $snapshot->counts,
            'metadata' => [
                'message' => $snapshot->data['message'] ?? 'Crawl completed.',
                'source' => self::class,
            ],
        ]);

        $this->logger->success('pipeline', [
            'job_id' => $event['job_id'],
            'pipeline_stage' => 'scrape_to_convert_trigger',
            'output_dir' => $snapshot->datasetPath,
        ]);

        if ($snapshot->datasetPath === '') {
            return;
        }

        $pipelineJob = $this->jobs->findWithTaskByJobId((string) $event['job_id']);
        if (! $pipelineJob?->task_id) {
            return;
        }

        $pipelineJob = $this->jobStates->markScrapeMonitorCompleted(
            $pipelineJob,
            $snapshot->datasetPath,
            $this->policy->carbonNow(),
            array_merge($pipelineJob->metadata ?? [], [
                'source' => self::class,
                'crawlerStatus' => 'completed',
            ]),
        );

        $this->outputs->publish($pipelineJob, $snapshot->datasetPath);
    }
}
