<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Clients\ScrapeCrawlerClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

readonly class ScrapeCrawlerTaskFallbackStarter
{
    public function __construct(
        private ScrapeCrawlerClient $crawler,
        private PipelineStateService $pipelineState,
        private ConfigRepository $config,
    ) {}

    public function start(string $taskId, array $options = [], ?array $taskUiResult = null): array
    {
        return $taskUiResult ?? [
            'success' => false, 'status' => 409,
            'message' => 'Crawler execution requires the job-based crawl protocol.',
            'taskId' => $taskId,
        ];
    }
}
