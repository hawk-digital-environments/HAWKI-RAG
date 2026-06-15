<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

use App\Services\Scrape\Clients\ScrapeCrawlerClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class ScrapeTaskService
{
    private const TASKS_UNAVAILABLE_MESSAGE = 'Please build the HAWKI-Scraper for getting available tasks.';

    public function __construct(
        private readonly ScrapeCrawlerClient $crawler,
        private readonly ScrapeTaskUiClient $taskUi,
        private readonly ScrapeTaskNormalizer $normalizer,
        private readonly ScrapeTaskStarter $starter,
        private readonly ConfigRepository $config,
    ) {}

    public function list(): array
    {
        $taskUiResult = $this->listTaskUiTasks();
        if (($taskUiResult['success'] ?? false) && ($taskUiResult['tasks'] ?? []) !== []) {
            return $taskUiResult;
        }

        $result = $this->crawler->request('GET', (string) $this->config->get('scraper.tasks_path', '/tasks'));
        if (! ($result['success'] ?? false)) {
            return $this->tasksUnavailable(($taskUiResult['data'] ?? null) !== null ? $taskUiResult : $result);
        }

        $tasks = $this->normalizer->normalizeCrawlerTasks($result['data'] ?? []);
        if ($tasks === []) {
            return $this->tasksUnavailable($result, 200);
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Scraper tasks loaded.',
            'tasks' => $tasks,
            'data' => $result['data'] ?? [],
        ];
    }

    public function start(string $taskId, array $options = []): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Task ID is required.',
            ];
        }

        $taskUiResult = $this->listTaskUiTasks();
        $taskUiStart = $this->starter->startTaskUiJob($taskId, $options, $taskUiResult);
        if ($taskUiStart['success'] ?? false) {
            return $taskUiStart;
        }

        return $this->starter->startCrawlerFallbackTask($taskId, $options, $taskUiStart);
    }

    private function listTaskUiTasks(): array
    {
        $profilesResult = $this->taskUi->profiles();
        if (! ($profilesResult['success'] ?? false)) {
            return $profilesResult;
        }

        $tasksResult = $this->taskUi->tasks();
        if (! ($tasksResult['success'] ?? false) && (int) ($tasksResult['status'] ?? 0) !== 404) {
            return $tasksResult;
        }

        $normalized = $this->normalizer->normalizeTaskUiList($profilesResult, $tasksResult);

        return [
            'success' => true,
            'status' => 200,
            'message' => $normalized['tasks'] === [] ? self::TASKS_UNAVAILABLE_MESSAGE : 'Scraper UI tasks loaded.',
            'tasks' => $normalized['tasks'],
            'data' => [
                'source' => 'scraper-task-ui',
                'profiles' => $normalized['profiles'],
                'storedTasks' => $normalized['storedTasks'],
            ],
        ];
    }

    private function tasksUnavailable(array $result, ?int $status = null): array
    {
        return [
            'success' => false,
            'status' => $status ?? (int) ($result['status'] ?? 502),
            'message' => self::TASKS_UNAVAILABLE_MESSAGE,
            'tasks' => [],
            'data' => $result['data'] ?? null,
        ];
    }
}
