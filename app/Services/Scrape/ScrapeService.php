<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Clients\ScrapeCrawlerClient;
use App\Services\Scrape\Data\ScrapeRequestResult;
use App\Services\Scrape\Repositories\ScrapeProcessRepository;
use App\Services\Scrape\Tasks\ScrapeTaskService;

class ScrapeService
{
    public function __construct(
        private readonly ScraperPipelineService $pipeline,
        private readonly PipelineStateService $pipelineState,
        private readonly ScrapeProcessRepository $processes,
        private readonly ScrapeRequestFactory $requests,
        private readonly ScrapeCrawlerClient $crawler,
        private readonly ScrapeTaskService $tasks,
        private readonly ScrapeDeletionService $deletions,
    ) {}

    public function startPipeline(array $request, ?callable $outputCallback = null): ScrapeRequestResult
    {
        return $this->pipeline->execute($this->requests->fromArray($request), $outputCallback);
    }

    /**
     * @return array<string, mixed>
     */
    public function stopPipeline(string $jobId): array
    {
        $result = $this->cancelCrawlerJob($jobId);

        if ($result['success'] ?? false) {
            $this->processes->updateStage($jobId, 'cancel_requested');
            $this->pipelineState->updateStage($jobId, PipelineStateService::STAGE_SCRAPE, [
                'status' => 'cancel_requested',
                'metadata' => ['message' => 'Crawler cancellation requested.'],
            ]);
        }

        return $result;
    }

    public function listCrawlerJobs(): array
    {
        return $this->crawler->listJobs();
    }

    public function listCrawlerTasks(): array
    {
        return $this->tasks->list();
    }

    public function startCrawlerTask(string $taskId, array $options = []): array
    {
        return $this->tasks->start($taskId, $options);
    }

    public function getCrawlerStatus(string $jobId): array
    {
        return $this->crawler->status($jobId);
    }

    public function cancelCrawlerJob(string $jobId): array
    {
        return $this->crawler->cancel($jobId);
    }

    public function pauseCrawlerJob(string $jobId): array
    {
        return $this->crawler->pause($jobId);
    }

    public function resumeCrawlerJob(string $jobId): array
    {
        return $this->crawler->resume($jobId);
    }

    public function listScrapeJobs(): array
    {
        return $this->processes->allAsArray();
    }

    public function deleteScrapeJob(string $jobId): bool
    {
        return $this->deletions->deleteJob($jobId);
    }

    public function deleteScrapeContent(string $jobId): bool
    {
        return $this->deletions->deleteContent($jobId);
    }

    public function getScrapeInformation(string $jobId): array
    {
        $process = $this->processes->findByJobIdOrFail($jobId);
        $data = $process->toArray();
        $data['stats'] = $process->stats;

        return $data;
    }

    public function getScrapeResult(string $jobId, int $elementId): array
    {
        $process = $this->processes->findByJobIdOrFail($jobId);

        return $process->elements()->findOrFail($elementId);
    }

    public function extractPageContent(string $url): array
    {
        return $this->crawler->extractPageContent($url);
    }
}
