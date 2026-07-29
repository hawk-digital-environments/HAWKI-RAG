<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

readonly class ScrapeTaskStarter
{
    public function __construct(
        private ScrapeTaskUiStartWorkflow $taskUi,
        private ScrapeCrawlerTaskFallbackStarter $fallback,
    ) {}

    public function startTaskUiJob(string $taskId, array $options, array $tasksResult): array
    {
        return $this->taskUi->start($taskId, $options, $tasksResult);
    }

    public function startCrawlerFallbackTask(string $taskId, array $options = [], ?array $taskUiResult = null): array
    {
        return $this->fallback->start($taskId, $options, $taskUiResult);
    }
}
