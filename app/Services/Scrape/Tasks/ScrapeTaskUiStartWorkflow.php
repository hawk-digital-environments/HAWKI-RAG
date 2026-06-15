<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Clients\ScrapeCrawlerClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

readonly class ScrapeTaskUiStartWorkflow
{
    public function __construct(
        private ScrapeCrawlerClient $crawler,
        private ScrapeTaskUiClient $taskUi,
        private ScrapeTaskNormalizer $normalizer,
        private PipelineStateService $pipelineState,
        private ClockInterface $clock = new Clock,
    ) {}

    public function start(string $taskId, array $options, array $tasksResult): array
    {
        if (! ($tasksResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $tasksResult['status'] ?? 502,
                'message' => $tasksResult['message'] ?? 'Scraper UI tasks could not be loaded.',
                'taskId' => $taskId,
                'data' => $tasksResult['data'] ?? null,
            ];
        }

        $task = null;
        foreach ($tasksResult['tasks'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $taskId) {
                $task = $candidate;
                break;
            }
        }

        if ($task === null) {
            return [
                'success' => false,
                'status' => 404,
                'message' => "Scraper UI task {$taskId} was not found.",
                'taskId' => $taskId,
                'data' => $tasksResult['data'] ?? null,
            ];
        }

        $profileId = $this->normalizer->firstScalar([$task['profileId'] ?? null]);
        if ($profileId === null) {
            return [
                'success' => false,
                'status' => 422,
                'message' => "Scraper UI task {$taskId} has no profile ID.",
                'taskId' => $taskId,
                'data' => $task,
            ];
        }

        $profileResult = $this->taskUi->profile($profileId);
        if (! ($profileResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $profileResult['status'] ?? 502,
                'message' => $profileResult['message'] ?? "Profile {$profileId} could not be loaded from the scraper UI.",
                'taskId' => $taskId,
                'data' => $profileResult['data'] ?? null,
            ];
        }

        $profile = $this->normalizer->taskUiProfileToUi($profileResult['data'] ?? []);
        if ($profile === null) {
            return [
                'success' => false,
                'status' => 502,
                'message' => "Profile {$profileId} from the scraper UI is invalid.",
                'taskId' => $taskId,
                'data' => $profileResult['data'] ?? null,
            ];
        }

        $submittedAt = $this->clock->now()->format(\DateTimeInterface::ATOM);
        $start = $this->normalizer->taskUiStartPayload($task, $profile, $options);
        $result = $this->taskUi->submit($start['payload']);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $result['status'] ?? 502,
                'message' => $result['message'] ?? 'Scraper UI task could not be started.',
                'taskId' => $taskId,
                'data' => $result['data'] ?? null,
            ];
        }

        $jobId = $this->crawler->extractJobId($result['data'] ?? []) ?? $start['jobId'];
        $this->pipelineState->startStage($jobId, PipelineStateService::STAGE_SCRAPE, [
            'metadata' => [
                'source' => 'scraper-task-ui',
                'taskId' => $taskId,
                'profileId' => $profile['id'],
                'profileName' => $profile['name'],
                'sourceUrl' => $start['payload']['url'] ?? null,
                'siteProfilePath' => $start['payload']['site_profile_path'] ?? null,
                'submittedAt' => $submittedAt,
                'scraperResponse' => $result['data'] ?? [],
            ],
        ]);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Scraper UI task started.',
            'taskId' => $taskId,
            'jobId' => $jobId,
            'data' => [
                'source' => 'scraper-task-ui',
                'task' => $task,
                'profile' => $profile,
                'payload' => $start['payload'],
                'response' => $result['data'] ?? [],
            ],
        ];
    }
}
