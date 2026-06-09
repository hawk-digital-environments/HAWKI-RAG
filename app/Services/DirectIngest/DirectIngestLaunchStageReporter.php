<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DirectIngestLaunchStageReporter
{
    public function __construct(
        private PipelineStageLogger $logger,
        private PipelineStateService $pipelineState,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function started(string $pipelineJobId, string $path, string $collection, string $statusMode, array $data): void
    {
        $this->logger->started('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'graph' => ! empty($data['graph']),
            'graph_only' => ! empty($data['graph_only']),
        ]);
        $this->pipelineState->startStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
            'dataset_path' => $path,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 1,
                'completed' => 0,
                'failed' => 0,
            ],
            'metadata' => [
                'mode' => 'direct-ui',
                'statusMode' => $statusMode,
                'collection' => $collection,
                'graph' => ! empty($data['graph']),
                'graphOnly' => ! empty($data['graph_only']),
                'resumeMode' => $data['resume_mode'] ?? 'resume',
            ],
        ]);
    }

    public function launched(string $pipelineJobId, string $path, string $collection, int $pid, string $statusPath): void
    {
        $this->logger->success('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'pid' => $pid,
            'status_path' => $statusPath,
        ]);
    }

    public function launchFailed(string $pipelineJobId, string $path, string $collection): void
    {
        $this->logger->failed('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'error_message' => 'Failed to launch ingest process.',
        ]);
        $this->pipelineState->failStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
            'dataset_path' => $path,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 1,
            ],
            'errors' => [[
                'message' => 'Failed to launch ingest process.',
                'updatedAt' => $this->timestamp(),
            ]],
            'metadata' => ['mode' => 'direct-ui'],
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
