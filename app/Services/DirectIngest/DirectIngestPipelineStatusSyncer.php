<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DirectIngestPipelineStatusSyncer
{
    public function __construct(
        private PipelineStateService $pipelineState,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $status
     */
    public function sync(array $status): void
    {
        $pipelineJobId = (string) ($status['pipeline_job_id'] ?? '');
        if ($pipelineJobId === '') {
            return;
        }

        $payload = [
            'dataset_path' => $status['path'] ?? null,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 0,
                'completed' => ($status['status'] ?? null) === 'completed' ? 1 : 0,
                'failed' => ($status['status'] ?? null) === 'failed' ? 1 : 0,
            ],
            'metadata' => [
                'mode' => 'direct-ui',
                'pid' => $status['pid'] ?? null,
                'collection' => $status['collection'] ?? null,
                'lastLine' => $status['last_line'] ?? null,
                'exitCode' => $status['exit_code'] ?? null,
            ],
        ];

        if (($status['status'] ?? null) === 'completed') {
            $this->pipelineState->completeStage($pipelineJobId, PipelineStateService::STAGE_INGEST, $payload);

            return;
        }

        if (($status['status'] ?? null) === 'failed') {
            $this->pipelineState->failStage($pipelineJobId, PipelineStateService::STAGE_INGEST, array_merge($payload, [
                'errors' => [[
                    'message' => $status['last_line'] ?? 'Ingest process failed.',
                    'updatedAt' => $this->timestamp(),
                ]],
            ]));
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
