<?php

declare(strict_types=1);

namespace App\Services\Scrape;

readonly class ScrapeTaskStarter
{
    public function __construct(
        private ScrapeTaskUiStartWorkflow $taskUi,
        private ScrapeLegacyCrawlerTaskStarter $legacy,
    ) {}

    public function startTaskUiJob(string $taskId, array $options, array $tasksResult): array
    {
        return $this->taskUi->start($taskId, $options, $tasksResult);
    }

    public function startLegacyCrawlerTask(string $taskId, array $options = [], ?array $taskUiResult = null): array
    {
        return $this->legacy->start($taskId, $options, $taskUiResult);
    }
}
