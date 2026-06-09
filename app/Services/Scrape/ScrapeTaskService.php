<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Models\PipelineJob;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class ScrapeTaskService
{
    private const TASKS_UNAVAILABLE_MESSAGE = 'Please build the HAWKI-Scraper for getting available tasks.';

    public function __construct(
        private readonly ScrapeCrawlerClient $crawler,
        private readonly PipelineStateService $pipelineState,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock = new Clock,
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

        $tasks = $this->normalizeCrawlerTasks($result['data'] ?? []);
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

        $taskUiResult = $this->startTaskUiJob($taskId, $options);
        if ($taskUiResult['success'] ?? false) {
            return $taskUiResult;
        }

        return $this->startLegacyCrawlerTask($taskId, $options, $taskUiResult);
    }

    private function startLegacyCrawlerTask(string $taskId, array $options = [], ?array $taskUiResult = null): array
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

    private function listTaskUiTasks(): array
    {
        $profilesResult = $this->taskUiRequest('GET', (string) $this->config->get('scraper.task_ui_profiles_path', '/api/profiles'));
        if (! ($profilesResult['success'] ?? false)) {
            return $profilesResult;
        }

        $tasksResult = $this->taskUiRequest('GET', (string) $this->config->get('scraper.task_ui_tasks_path', '/api/tasks'));
        if (! ($tasksResult['success'] ?? false) && (int) ($tasksResult['status'] ?? 0) !== 404) {
            return $tasksResult;
        }

        $profileEntries = $this->taskUiProfileEntries($profilesResult['data'] ?? []);
        $profiles = [];
        foreach ($profileEntries as $entry) {
            $profile = $this->taskUiProfileToUi($entry);
            if ($profile !== null) {
                $profiles[$profile['id']] = $profile;
            }
        }

        $storedTasks = ($tasksResult['success'] ?? false)
            ? $this->normalizeTaskUiStoredTasks($tasksResult['data'] ?? [], $profiles)
            : [];
        $storedIds = array_fill_keys(array_map(static fn (array $task): string => $task['id'], $storedTasks), true);

        $defaultTasks = [];
        foreach ($profiles as $profile) {
            $task = $this->taskUiTaskForProfile($profile);
            if (! isset($storedIds[$task['id']])) {
                $defaultTasks[] = $task;
            }
        }

        $tasks = array_merge($defaultTasks, $storedTasks);
        usort($tasks, static fn (array $a, array $b): int => strcasecmp($a['label'] ?? $a['id'], $b['label'] ?? $b['id']));

        return [
            'success' => true,
            'status' => 200,
            'message' => $tasks === [] ? self::TASKS_UNAVAILABLE_MESSAGE : 'Scraper UI tasks loaded.',
            'tasks' => $tasks,
            'data' => [
                'source' => 'scraper-task-ui',
                'profiles' => array_values($profiles),
                'storedTasks' => $storedTasks,
            ],
        ];
    }

    private function startTaskUiJob(string $taskId, array $options = []): array
    {
        $tasksResult = $this->listTaskUiTasks();
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

        $profileId = $this->crawler->firstScalar([$task['profileId'] ?? null]);
        if ($profileId === null) {
            return [
                'success' => false,
                'status' => 422,
                'message' => "Scraper UI task {$taskId} has no profile ID.",
                'taskId' => $taskId,
                'data' => $task,
            ];
        }

        $profileResult = $this->taskUiRequest('GET', $this->taskUiProfilePath($profileId));
        if (! ($profileResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $profileResult['status'] ?? 502,
                'message' => $profileResult['message'] ?? "Profile {$profileId} could not be loaded from the scraper UI.",
                'taskId' => $taskId,
                'data' => $profileResult['data'] ?? null,
            ];
        }

        $profile = $this->taskUiProfileToUi($profileResult['data'] ?? []);
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
        $start = $this->taskUiStartPayload($task, $profile, $options);
        $result = $this->taskUiRequest('POST', (string) $this->config->get('scraper.task_ui_submit_path', '/api/crawler/submit'), $start['payload']);

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

    private function taskUiRequest(string $method, string $path, array $payload = []): array
    {
        $baseUrl = $this->taskUiBaseUrl();
        if ($baseUrl === '') {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Scraper task UI URL is not configured.',
            ];
        }

        try {
            $request = $this->http->timeout(10)->acceptJson()->retry(1, 250, throw: false);
            $method = strtoupper($method);
            $response = $method === 'GET'
                ? $request->get($baseUrl.'/'.ltrim($path, '/'), $payload)
                : $request->send($method, $baseUrl.'/'.ltrim($path, '/'), ['json' => $payload]);
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \JsonException('Expected JSON object response.');
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $data,
                'message' => $response->successful()
                    ? $this->successMessageFromCrawlerData($data)
                    : $this->errorMessageFromCrawlerData($data, $response->status()),
            ];
        } catch (\JsonException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Scraper task UI returned invalid JSON: '.$exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function taskUiBaseUrl(): string
    {
        $url = trim((string) $this->config->get('scraper.task_ui_url', ''));
        if ($url === '') {
            return '';
        }

        return rtrim(preg_replace('#/tasks/?$#', '', $url) ?? $url, '/');
    }

    private function taskUiProfilePath(string $profileId): string
    {
        return rtrim((string) $this->config->get('scraper.task_ui_profiles_path', '/api/profiles'), '/')
            .'/'
            .rawurlencode($profileId);
    }

    private function taskUiProfileEntries(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $profiles = $data['profiles'] ?? $data['data']['profiles'] ?? $data;

        return is_array($profiles) && $this->crawler->isList($profiles) ? $profiles : [];
    }

    private function taskUiProfileToUi(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $id = $this->crawler->firstScalar([$entry['name'] ?? null]);
        if ($id === null) {
            return null;
        }

        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        $sitemap = is_array($profile['sitemap'] ?? null) ? $profile['sitemap'] : [];
        $hostEntrypoints = [];
        foreach ($this->stringList($entry['match_hosts'] ?? []) as $host) {
            if (! str_starts_with($host, '*.')) {
                $hostEntrypoints[] = ['type' => 'host', 'src' => $host];
            }
        }

        $entrypoints = $hostEntrypoints;
        $sitemapBase = $this->crawler->firstScalar([$sitemap['base_url'] ?? null]);
        if ($sitemapBase !== null) {
            $entrypoints[] = ['type' => 'sitemap', 'src' => $sitemapBase];
        }

        return [
            'id' => $id,
            'name' => $this->crawler->firstScalar([$profile['name'] ?? null, $id]) ?? $id,
            'containerPath' => $this->crawler->firstScalar([$entry['containerPath'] ?? null]),
            'entrypoints' => $entrypoints,
            'rescrape_failed' => is_bool($profile['rescrape_failed'] ?? null) ? $profile['rescrape_failed'] : false,
            'max_concurrency' => $this->taskUiNumber($profile, 'max_concurrency', 1),
            'max_rpm' => $this->taskUiNumber($profile, 'max_rpm', 60),
            'skip_images' => is_bool($profile['skip_images'] ?? null) ? $profile['skip_images'] : false,
            'max_images_per_page' => $this->taskUiNumber($profile, 'max_images_per_page', 30),
            'max_pages' => $this->taskUiNumber($profile, 'max_pages', 100),
            'max_link_density' => $this->taskUiNumber($profile, 'max_link_density', 0.4),
            'discovery_mode' => is_bool($profile['discovery_mode'] ?? null) ? $profile['discovery_mode'] : false,
            'raw' => $entry,
        ];
    }

    private function normalizeTaskUiStoredTasks(mixed $data, array $profiles): array
    {
        $tasks = [];
        foreach ($this->crawler->taskItems($data) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->crawler->firstScalar([$item['id'] ?? null]);
            $profileId = $this->crawler->firstScalar([$item['profileId'] ?? null, $item['profile_id'] ?? null]);
            if ($id === null || $profileId === null) {
                continue;
            }

            $profile = $profiles[$profileId] ?? null;
            $tasks[] = $this->taskUiTask([
                'id' => $id,
                'name' => $this->crawler->firstScalar([$item['name'] ?? null, $id]) ?? $id,
                'profileId' => $profileId,
                'profileName' => $profile['name'] ?? $profileId,
                'schedule' => $this->crawler->firstScalar([$item['schedule'] ?? null]),
                'containerPath' => $profile['containerPath'] ?? null,
                'entrypoints' => $profile['entrypoints'] ?? [],
                'settings' => $profile ? $this->taskUiSettings($profile) : [],
                'raw' => $item,
            ]);
        }

        return $tasks;
    }

    private function taskUiTaskForProfile(array $profile): array
    {
        return $this->taskUiTask([
            'id' => 'manual-'.$profile['id'],
            'name' => $profile['name'].' Manual Crawl',
            'profileId' => $profile['id'],
            'profileName' => $profile['name'],
            'schedule' => null,
            'containerPath' => $profile['containerPath'] ?? null,
            'entrypoints' => $profile['entrypoints'] ?? [],
            'settings' => $this->taskUiSettings($profile),
            'raw' => $profile['raw'] ?? $profile,
        ]);
    }

    private function taskUiTask(array $task): array
    {
        $schedule = $this->crawler->firstScalar([$task['schedule'] ?? null]);
        $profileName = $this->crawler->firstScalar([$task['profileName'] ?? null, $task['profileId'] ?? null]);
        $entrypoints = $this->taskUiEntrypoints($task['entrypoints'] ?? []);
        $firstHost = $this->taskUiFirstEntrypoint(['entrypoints' => $entrypoints], 'host');
        $firstSitemap = $this->taskUiFirstEntrypoint(['entrypoints' => $entrypoints], 'sitemap');
        $description = trim(implode(' | ', array_filter([
            $profileName ? 'Profile: '.$profileName : null,
            $schedule ? 'Schedule: '.$schedule : 'Manual task',
            $task['containerPath'] ?? null,
        ])));

        return [
            'id' => (string) $task['id'],
            'label' => (string) $task['name'],
            'description' => $description,
            'profileId' => (string) $task['profileId'],
            'profileName' => $profileName,
            'schedule' => $schedule,
            'type' => $schedule ? 'scheduled' : 'manual',
            'source' => 'scraper-task-ui',
            'primaryUrl' => $firstHost !== null ? 'https://'.$firstHost : $firstSitemap,
            'sitemapUrl' => $firstSitemap,
            'containerPath' => $this->crawler->firstScalar([$task['containerPath'] ?? null]),
            'entrypoints' => $entrypoints,
            'settings' => is_array($task['settings'] ?? null) ? $task['settings'] : [],
            'raw' => $task['raw'] ?? $task,
        ];
    }

    private function taskUiSettings(array $profile): array
    {
        return [
            'max_pages' => $profile['max_pages'],
            'max_concurrency' => $profile['max_concurrency'],
            'max_rpm' => $profile['max_rpm'],
            'skip_images' => $profile['skip_images'],
            'discovery_mode' => $profile['discovery_mode'],
            'rescrape_failed' => $profile['rescrape_failed'],
            'max_images_per_page' => $profile['max_images_per_page'],
            'max_link_density' => $profile['max_link_density'],
        ];
    }

    private function taskUiEntrypoints(mixed $entrypoints): array
    {
        if (! is_array($entrypoints)) {
            return [];
        }

        $normalized = [];
        foreach ($entrypoints as $entrypoint) {
            if (! is_array($entrypoint)) {
                continue;
            }

            $type = $this->crawler->firstScalar([$entrypoint['type'] ?? null]);
            $src = $this->crawler->firstScalar([$entrypoint['src'] ?? null]);
            if ($type === null || $src === null) {
                continue;
            }

            $normalized[] = ['type' => $type, 'src' => $src];
        }

        return $normalized;
    }

    private function taskUiStartPayload(array $task, array $profile, array $options): array
    {
        $firstHost = $this->taskUiFirstEntrypoint($profile, 'host');
        $firstSitemap = $this->taskUiFirstEntrypoint($profile, 'sitemap');
        $jobId = $this->crawler->firstScalar([$options['job_id'] ?? null, $options['jobId'] ?? null])
            ?? ($task['profileId'].'_'.((int) floor(microtime(true) * 1000)));

        $payload = [
            'job_id' => $jobId,
            'output_dir' => 'output',
            'site_profile_path' => $profile['containerPath'],
            'rescrape_failed' => $profile['rescrape_failed'],
            'sitemap' => $firstSitemap !== null,
            'max_pages' => $profile['max_pages'],
            'max_concurrency' => $profile['max_concurrency'],
            'max_rpm' => $profile['max_rpm'],
            'skip_images' => $profile['skip_images'],
            'max_images_per_page' => $profile['max_images_per_page'],
            'max_link_density' => $profile['max_link_density'],
            'discovery_mode' => $profile['discovery_mode'],
        ];

        if ($firstHost !== null) {
            $payload['url'] = 'https://'.$firstHost;
        } elseif ($firstSitemap !== null) {
            $payload['url'] = $firstSitemap;
        }

        if ($firstSitemap !== null) {
            $payload['sitemap_base'] = $firstSitemap;
        }

        return ['jobId' => $jobId, 'payload' => $payload];
    }

    private function taskUiFirstEntrypoint(array $profile, string $type): ?string
    {
        foreach ($profile['entrypoints'] ?? [] as $entrypoint) {
            if (is_array($entrypoint) && ($entrypoint['type'] ?? null) === $type) {
                return $this->crawler->firstScalar([$entrypoint['src'] ?? null]);
            }
        }

        return null;
    }

    private function taskUiNumber(array $profile, string $key, int|float $default): int|float
    {
        $value = $profile[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : $default;
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function taskStartPath(string $taskId): string
    {
        $path = (string) $this->config->get('scraper.task_start_path', '/tasks/{task}/run');
        $encoded = rawurlencode($taskId);

        return str_replace(['{task}', '{taskId}', ':task', ':taskId'], $encoded, $path);
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

    private function normalizeCrawlerTasks(mixed $data): array
    {
        $tasks = [];
        foreach ($this->crawler->taskItems($data) as $key => $item) {
            $raw = $item;
            if (is_scalar($item)) {
                $item = ['id' => (string) $item, 'label' => (string) $item];
            }

            if (! is_array($item)) {
                continue;
            }

            $id = $this->crawler->firstScalar([
                $item['task_id'] ?? null,
                $item['taskId'] ?? null,
                $item['id'] ?? null,
                $item['slug'] ?? null,
                $item['name'] ?? null,
                is_scalar($key) ? $key : null,
            ]);

            if ($id === null || $id === '') {
                continue;
            }

            $tasks[] = [
                'id' => $id,
                'label' => $this->crawler->firstScalar([$item['label'] ?? null, $item['title'] ?? null, $item['name'] ?? null, $id]),
                'description' => $this->crawler->firstScalar([$item['description'] ?? null, $item['summary'] ?? null, $item['url'] ?? null]),
                'status' => $this->crawler->firstScalar([$item['status'] ?? null, $item['state'] ?? null]),
                'routingKey' => $this->crawler->firstScalar([$item['routing_key'] ?? null, $item['routingKey'] ?? null]),
                'profileId' => $this->crawler->firstScalar([$item['profile_id'] ?? null, $item['profileId'] ?? null]),
                'schedule' => $this->crawler->firstScalar([$item['schedule'] ?? null, $item['cron'] ?? null]),
                'type' => $this->crawler->firstScalar([$item['type'] ?? null, $item['kind'] ?? null]) ?? 'legacy',
                'source' => 'crawler-api',
                'primaryUrl' => $this->crawler->firstScalar([$item['source_url'] ?? null, $item['sourceUrl'] ?? null, $item['url'] ?? null]),
                'raw' => $raw,
            ];
        }

        return $tasks;
    }

    private function successMessageFromCrawlerData(array $data): string
    {
        foreach (['message', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return 'Crawler request completed successfully.';
    }

    private function errorMessageFromCrawlerData(array $data, int $status): string
    {
        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.(is_string($data['detail']) ? $data['detail'] : json_encode($data['detail']));
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['error']) && is_scalar($data['error']) && trim((string) $data['error']) !== '') {
            return (string) $data['error'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }
}
