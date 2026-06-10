<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

readonly class ScrapeLegacyCrawlerTaskStarter
{
    public function __construct(
        private ScrapeCrawlerClient $crawler,
        private PipelineStateService $pipelineState,
        private ConfigRepository $config,
    ) {}

    public function start(string $taskId, array $options = [], ?array $taskUiResult = null): array
    {
        $payload = array_merge($options, ['task_id' => $taskId]);
        $result = $this->crawler->request(
            strtoupper((string) $this->config->get('scraper.task_start_method', 'POST')),
            $this->taskStartPath($taskId),
            $payload,
        );

        if (! ($result['success'] ?? false)) {
            if ($taskUiResult !== null && ($taskUiResult['status'] ?? 502) !== 502) {
                return $taskUiResult;
            }

            return [
                'success' => false,
                'status' => $result['status'] ?? 502,
                'message' => $result['message'] ?? 'Scraper task could not be started.',
                'taskId' => $taskId,
                'data' => $result['data'] ?? null,
            ];
        }

        $jobId = $this->crawler->extractJobId($result['data'] ?? []);
        if ($jobId === null) {
            return [
                'success' => false,
                'status' => 502,
                'message' => 'Scraper task started but no job ID was returned.',
                'taskId' => $taskId,
                'data' => $result['data'] ?? null,
            ];
        }

        $this->pipelineState->startStage($jobId, PipelineStateService::STAGE_SCRAPE, [
            'metadata' => [
                'source' => 'scraper-task',
                'taskId' => $taskId,
                'scraperResponse' => $result['data'] ?? [],
            ],
        ]);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Scraper task started.',
            'taskId' => $taskId,
            'jobId' => $jobId,
            'data' => $result['data'] ?? [],
        ];
    }

    private function taskStartPath(string $taskId): string
    {
        $path = (string) $this->config->get('scraper.task_start_path', '/tasks/{task}/run');
        $encoded = rawurlencode($taskId);

        return str_replace(['{task}', '{taskId}', ':task', ':taskId'], $encoded, $path);
    }
}
