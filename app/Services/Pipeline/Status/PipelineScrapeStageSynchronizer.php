<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineScrapeStageSynchronizer
{
    public function __construct(private PipelineStateService $pipelineState)
    {
    }

    /**
     * @param array<string, mixed> $stage
     */
    public function sync(string $jobId, array $stage): void
    {
        $status = (string) ($stage['status'] ?? 'unknown');
        $payload = [
            'dataset_path' => $stage['dataset_path'] ?? $stage['datasetPath'] ?? null,
            'counts' => [
                'total_pages' => (int) ($stage['counts']['total_pages'] ?? $stage['counts']['totalPages'] ?? 0),
                'pages_crawled' => (int) ($stage['counts']['pages_crawled'] ?? $stage['counts']['pagesCrawled'] ?? 0),
                'failed_urls' => (int) ($stage['counts']['failed_urls'] ?? $stage['counts']['failedUrls'] ?? 0),
            ],
            'errors' => $stage['errors'] ?? [],
            'warnings' => $stage['warnings'] ?? [],
            'metadata' => [
                'message' => $stage['message'] ?? null,
                'source' => $stage['source'] ?? [],
            ],
        ];

        if ($status === 'completed') {
            $this->pipelineState->completeStage($jobId, PipelineStateService::STAGE_SCRAPE, $payload);

            return;
        }

        if ($status === 'failed') {
            $this->pipelineState->failStage($jobId, PipelineStateService::STAGE_SCRAPE, $payload);

            return;
        }

        if (! in_array($status, ['unknown', 'pending'], true)) {
            $this->pipelineState->updateStage($jobId, PipelineStateService::STAGE_SCRAPE, array_merge($payload, [
                'status' => in_array($status, ['running', 'processing', 'received'], true) ? $status : 'running',
            ]));
        }
    }
}
