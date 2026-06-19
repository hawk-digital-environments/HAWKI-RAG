<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Scrape\Tasks\ScrapeTaskService;
use App\Services\Scrape\Clients\ScrapeCrawlerClient;

class ScrapeService
{
    public function __construct(
        private readonly ScrapeCrawlerClient $crawler,
        private readonly ScrapeTaskService $tasks,
    ) {}

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
}
