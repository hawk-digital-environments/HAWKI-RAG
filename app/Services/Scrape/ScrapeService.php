<?php

namespace App\Services\Scrape;

use App\Models\ScrapeProcess;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeRequestResult;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

class ScrapeService
{
    private const TASKS_UNAVAILABLE_MESSAGE = 'Please build the HAWKI-Scraper for getting available tasks.';

    /** ----------------
     *  PIPELINE CONTROLS
     --------------- **/

    /**
     * Start the scraping pipeline
     */
    public function startPipeline(array $request, ?callable $outputCallback = null): ScrapeRequestResult
    {
        $defaults = config('scraper.defaults', []);
        $url = $this->normalizeUrl((string) ($request['url'] ?? ''));
        $label = $this->normalizeLabel((string) ($request['label'] ?? ''), $url);

        $jobRequest = new ScrapeJobRequest(
            url: $url,
            label: $label,
            maxPages: (int) ($request['maxPages'] ?? $defaults['max_pages'] ?? 100),
            outputDir: $this->resolveOutputDir($request['outputDir'] ?? null, $label),
            skipImages: $this->boolValue($request['skipImages'] ?? $defaults['skip_images'] ?? false),
            imageExceptions: $this->normalizeImageExceptions($request['imageExceptions'] ?? null),
            dateSelector: $request['dateSelector'] ?? null,
            maxConcurrency: (int) ($request['maxConcurrency'] ?? $defaults['max_concurrency'] ?? 4),
            maxRpm: (int) ($request['maxRpm'] ?? $defaults['max_rpm'] ?? 60),
            requestDelay: isset($request['requestDelay']) ? (int) $request['requestDelay'] : null,
            discoveryMode: $this->boolValue($request['discoveryMode'] ?? $defaults['discovery_mode'] ?? false),
        );
        $pipeline = app(ScraperPipelineService::class);

        return $pipeline->execute($jobRequest, $outputCallback);
    }

    /**
     *  STOP the scraping pipeline
     *  NOT IMPLIMENTED YET
     *
     *  @todo Create a stop mechanism for scrape process
     *
     * @return array<string, mixed>
     */
    public function stopPipeline(string $jobId): array
    {
        $result = $this->cancelCrawlerJob($jobId);

        if ($result['success'] ?? false) {
            ScrapeProcess::where('job_id', $jobId)->update(['stage' => 'cancel_requested']);
            app(PipelineStateService::class)->updateStage($jobId, PipelineStateService::STAGE_SCRAPE, [
                'status' => 'cancel_requested',
                'metadata' => ['message' => 'Crawler cancellation requested.'],
            ]);
        }

        return $result;
    }

    public function listCrawlerJobs(): array
    {
        return $this->crawlerRequest('GET', '/jobs');
    }

    public function listCrawlerTasks(): array
    {
        $taskUiResult = $this->listTaskUiTasks();
        if (($taskUiResult['success'] ?? false) && ($taskUiResult['tasks'] ?? []) !== []) {
            return $taskUiResult;
        }

        $path = (string) config('scraper.tasks_path', '/tasks');
        $result = $this->crawlerRequest('GET', $path);

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

    public function startCrawlerTask(string $taskId, array $options = []): array
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
        $path = $this->taskStartPath($taskId);
        $method = strtoupper((string) config('scraper.task_start_method', 'POST'));
        $payload = array_merge($options, [
            'task_id' => $taskId,
        ]);

        $result = $this->crawlerRequest($method, $path, $payload);
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

        $jobId = $this->extractJobId($result['data'] ?? []);
        if ($jobId === null) {
            return [
                'success' => false,
                'status' => 502,
                'message' => 'Scraper task started but no job ID was returned.',
                'taskId' => $taskId,
                'data' => $result['data'] ?? null,
            ];
        }

        app(PipelineStateService::class)->startStage($jobId, PipelineStateService::STAGE_SCRAPE, [
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

    public function getCrawlerStatus(string $jobId): array
    {
        return $this->crawlerRequest('GET', "/status/{$jobId}");
    }

    public function cancelCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/cancel");
    }

    public function pauseCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/pause");
    }

    public function resumeCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/resume");
    }

    /** ------------------
     *  SCRAPE INFORMATION
    ------------------- **/

    /**
     * List all scrape processes
     */
    public function listScrapeJobs(): array
    {
        return ScrapeProcess::all()->toArray();
    }

    /**
     * Delete scraped Data
     *
     * @throws Exception
     */
    public function deleteScrapeJob(string $jobId): bool
    {
        try {
            $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
            $elements = $process->elements;
            foreach ($elements as $element) {
                $element->delete();
            }
            $process->stats()->delete();
            $process->delete();

            return true;
        } catch (Exception $exception) {
            Log::error('failed to delete scrape job '.$jobId.': '.$exception->getMessage());

            return false;
        }
    }

    /**
     * Removes scraped files but keeps the database information
     * For after the time when data is already vectorized.
     *
     * @return bool
     */
    public function deleteScrapeContent(string $jobId): bool
    {
        try {
            $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
            $request = $process->request ?? [];
            $outputDir = (string) ($request['output_dir'] ?? $request['outputDir'] ?? '');

            if ($outputDir === '') {
                return true;
            }

            $storageRoot = realpath((string) config('scraper.storage_path'));
            $target = realpath($outputDir);

            if ($storageRoot === false || $target === false) {
                return true;
            }

            if ($target === $storageRoot || ! str_starts_with($target, $storageRoot.DIRECTORY_SEPARATOR)) {
                Log::warning("refusing to delete scrape content outside storage root for job {$jobId}", [
                    'storage_root' => $storageRoot,
                    'target' => $target,
                ]);

                return false;
            }

            return File::deleteDirectory($target);
        } catch (Exception $exception) {
            Log::error('failed to delete scrape content '.$jobId.': '.$exception->getMessage());

            return false;
        }
    }

    /**
     * Get Specific Scrape Process information.
     */
    public function getScrapeInformation(string $jobId): array
    {
        $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
        $data = $process->toArray();
        $data['stats'] = $process->stats;

        return $data;
    }

    /**
     * Get Specific ScrapedElement
     *
     * @todo extract the file from the storage
     */
    public function getScrapeResult(string $jobId, int $elementId): array
    {
        $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();

        return $process->elements()->findOrFail($elementId);
    }

    /** ------------------
     *  Extract Page Content
    ------------------- **/

    /**
     * extracts the content of a specific page.
     *
     * @throws ConnectionException
     */
    public function extractPageContent(string $url): array
    {
        try {
            $response = Http::timeout(300)
                ->retry(2, 500, throw: false)
                ->post(config('scraper.api_url').'/scrape', [
                    'url' => $url,
                ]);

            $data = $this->decodeJsonResponse($response->body());
            $success = $response->successful() && (bool) ($data['success'] ?? false);

            return [
                'success' => $success,
                'status' => $success ? $response->status() : ($response->successful() ? 502 : $response->status()),
                'data' => $data,
                'message' => $success
                    ? 'Page content extracted successfully.'
                    : $this->errorMessageFromCrawlerData($data, $response->status()),
            ];
        } catch (JsonException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Crawler returned invalid JSON: '.$exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            Log::error('failed to extract page content '.$exception->getMessage());

            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function resolveOutputDir(mixed $outputDir, string $label): string
    {
        if (is_string($outputDir) && trim($outputDir) !== '') {
            return trim($outputDir);
        }

        return rtrim((string) config('scraper.storage_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .Str::slug($label, '-');
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || File::exists($url)) {
            return $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return $url;
        }

        return 'https://'.$url;
    }

    private function normalizeLabel(string $label, string $url): string
    {
        $slug = Str::slug(trim($label), '-');
        if ($slug !== '') {
            return $slug;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $fallback = is_string($host) && $host !== '' ? $host : 'pipeline-test';

        return Str::slug($fallback, '-') ?: 'pipeline-test';
    }

    private function normalizeImageExceptions(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (is_array($value)) {
            $selectors = array_values(array_filter(
                array_map(static fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value),
                static fn ($item) => $item !== ''
            ));

            return $selectors === [] ? null : implode(',', $selectors);
        }

        throw new \InvalidArgumentException('Image exceptions must be a string or an array of CSS selectors.');
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function crawlerRequest(string $method, string $path, array $payload = []): array
    {
        try {
            $url = rtrim((string) config('scraper.api_url'), '/').'/'.ltrim($path, '/');
            $request = Http::timeout(30)
                ->withHeaders($this->crawlerHeaders())
                ->retry(2, 500, throw: false);

            $method = strtoupper($method);
            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->send($method, $url, ['json' => $payload]);

            $data = $this->decodeJsonResponse($response->body());
            $success = $response->successful();

            return [
                'success' => $success,
                'status' => $response->status(),
                'data' => $data,
                'message' => $success
                    ? $this->successMessageFromCrawlerData($data)
                    : $this->errorMessageFromCrawlerData($data, $response->status()),
            ];
        } catch (JsonException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Crawler returned invalid JSON: '.$exception->getMessage(),
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

    private function listTaskUiTasks(): array
    {
        $profilesResult = $this->taskUiRequest('GET', (string) config('scraper.task_ui_profiles_path', '/api/profiles'));
        if (! ($profilesResult['success'] ?? false)) {
            return $profilesResult;
        }

        $tasksResult = $this->taskUiRequest('GET', (string) config('scraper.task_ui_tasks_path', '/api/tasks'));
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
        $storedIds = array_fill_keys(array_map(static fn (array $task) => $task['id'], $storedTasks), true);

        $defaultTasks = [];
        foreach ($profiles as $profile) {
            $task = $this->taskUiTaskForProfile($profile);
            if (! isset($storedIds[$task['id']])) {
                $defaultTasks[] = $task;
            }
        }

        $tasks = array_merge($defaultTasks, $storedTasks);
        usort($tasks, static fn (array $a, array $b) => strcasecmp($a['label'] ?? $a['id'], $b['label'] ?? $b['id']));

        return [
            'success' => true,
            'status' => 200,
            'message' => $tasks === []
                ? self::TASKS_UNAVAILABLE_MESSAGE
                : 'Scraper UI tasks loaded.',
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

        $profileId = $this->firstScalar([$task['profileId'] ?? null]);
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

        $submittedAt = now()->toJSON();
        $start = $this->taskUiStartPayload($task, $profile, $options);
        $result = $this->taskUiRequest('POST', (string) config('scraper.task_ui_submit_path', '/api/crawler/submit'), $start['payload']);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $result['status'] ?? 502,
                'message' => $result['message'] ?? 'Scraper UI task could not be started.',
                'taskId' => $taskId,
                'data' => $result['data'] ?? null,
            ];
        }

        $jobId = $this->extractJobId($result['data'] ?? []) ?? $start['jobId'];
        app(PipelineStateService::class)->startStage($jobId, PipelineStateService::STAGE_SCRAPE, [
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
            $url = $baseUrl.'/'.ltrim($path, '/');
            $request = Http::timeout(10)
                ->acceptJson()
                ->retry(1, 250, throw: false);

            $method = strtoupper($method);
            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->send($method, $url, ['json' => $payload]);

            $data = $this->decodeJsonResponse($response->body());
            $success = $response->successful();

            return [
                'success' => $success,
                'status' => $response->status(),
                'data' => $data,
                'message' => $success
                    ? $this->successMessageFromCrawlerData($data)
                    : $this->errorMessageFromCrawlerData($data, $response->status()),
            ];
        } catch (JsonException $exception) {
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
        $url = trim((string) config('scraper.task_ui_url', ''));
        if ($url === '') {
            return '';
        }

        return rtrim(preg_replace('#/tasks/?$#', '', $url) ?? $url, '/');
    }

    private function taskUiProfilePath(string $profileId): string
    {
        return rtrim((string) config('scraper.task_ui_profiles_path', '/api/profiles'), '/')
            .'/'
            .rawurlencode($profileId);
    }

    private function taskUiProfileEntries(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $profiles = $data['profiles'] ?? $data['data']['profiles'] ?? $data;

        return is_array($profiles) && $this->isList($profiles) ? $profiles : [];
    }

    private function taskUiProfileToUi(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $id = $this->firstScalar([$entry['name'] ?? null]);
        if ($id === null) {
            return null;
        }

        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        $sitemap = is_array($profile['sitemap'] ?? null) ? $profile['sitemap'] : [];
        $hostEntrypoints = [];
        foreach ($this->stringList($entry['match_hosts'] ?? []) as $host) {
            if (! str_starts_with($host, '*.')) {
                $hostEntrypoints[] = [
                    'type' => 'host',
                    'src' => $host,
                ];
            }
        }

        $entrypoints = $hostEntrypoints;
        $sitemapBase = $this->firstScalar([$sitemap['base_url'] ?? null]);
        if ($sitemapBase !== null) {
            $entrypoints[] = [
                'type' => 'sitemap',
                'src' => $sitemapBase,
            ];
        }

        return [
            'id' => $id,
            'name' => $this->firstScalar([$profile['name'] ?? null, $id]) ?? $id,
            'containerPath' => $this->firstScalar([$entry['containerPath'] ?? null]),
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
        $items = $this->taskItems($data);
        $tasks = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->firstScalar([$item['id'] ?? null]);
            $profileId = $this->firstScalar([$item['profileId'] ?? null, $item['profile_id'] ?? null]);
            if ($id === null || $profileId === null) {
                continue;
            }

            $profile = $profiles[$profileId] ?? null;
            $tasks[] = $this->taskUiTask([
                'id' => $id,
                'name' => $this->firstScalar([$item['name'] ?? null, $id]) ?? $id,
                'profileId' => $profileId,
                'profileName' => $profile['name'] ?? $profileId,
                'schedule' => $this->firstScalar([$item['schedule'] ?? null]),
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
        $schedule = $this->firstScalar([$task['schedule'] ?? null]);
        $profileName = $this->firstScalar([$task['profileName'] ?? null, $task['profileId'] ?? null]);
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
            'containerPath' => $this->firstScalar([$task['containerPath'] ?? null]),
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

            $type = $this->firstScalar([$entrypoint['type'] ?? null]);
            $src = $this->firstScalar([$entrypoint['src'] ?? null]);
            if ($type === null || $src === null) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'src' => $src,
            ];
        }

        return $normalized;
    }

    private function taskUiStartPayload(array $task, array $profile, array $options): array
    {
        $firstHost = $this->taskUiFirstEntrypoint($profile, 'host');
        $firstSitemap = $this->taskUiFirstEntrypoint($profile, 'sitemap');
        $jobId = $this->firstScalar([
            $options['job_id'] ?? null,
            $options['jobId'] ?? null,
        ]) ?? ($task['profileId'].'_'.((int) floor(microtime(true) * 1000)));

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

        return [
            'jobId' => $jobId,
            'payload' => $payload,
        ];
    }

    private function taskUiFirstEntrypoint(array $profile, string $type): ?string
    {
        foreach ($profile['entrypoints'] ?? [] as $entrypoint) {
            if (is_array($entrypoint) && ($entrypoint['type'] ?? null) === $type) {
                return $this->firstScalar([$entrypoint['src'] ?? null]);
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
            array_map(static fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn ($item) => $item !== ''
        ));
    }

    private function taskStartPath(string $taskId): string
    {
        $path = (string) config('scraper.task_start_path', '/tasks/{task}/run');
        $encoded = rawurlencode($taskId);

        return str_replace(['{task}', '{taskId}', ':task', ':taskId'], $encoded, $path);
    }

    private function crawlerHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];
        $apiKey = trim((string) config('scraper.api_key', ''));
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        return $headers;
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
        $items = $this->taskItems($data);
        $tasks = [];

        foreach ($items as $key => $item) {
            $raw = $item;
            if (is_scalar($item)) {
                $item = ['id' => (string) $item, 'label' => (string) $item];
            }

            if (! is_array($item)) {
                continue;
            }

            $id = $this->firstScalar([
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
                'label' => $this->firstScalar([
                    $item['label'] ?? null,
                    $item['title'] ?? null,
                    $item['name'] ?? null,
                    $id,
                ]),
                'description' => $this->firstScalar([
                    $item['description'] ?? null,
                    $item['summary'] ?? null,
                    $item['url'] ?? null,
                ]),
                'status' => $this->firstScalar([
                    $item['status'] ?? null,
                    $item['state'] ?? null,
                ]),
                'routingKey' => $this->firstScalar([
                    $item['routing_key'] ?? null,
                    $item['routingKey'] ?? null,
                ]),
                'profileId' => $this->firstScalar([
                    $item['profile_id'] ?? null,
                    $item['profileId'] ?? null,
                ]),
                'schedule' => $this->firstScalar([
                    $item['schedule'] ?? null,
                    $item['cron'] ?? null,
                ]),
                'type' => $this->firstScalar([
                    $item['type'] ?? null,
                    $item['kind'] ?? null,
                ]) ?? 'legacy',
                'source' => 'crawler-api',
                'primaryUrl' => $this->firstScalar([
                    $item['source_url'] ?? null,
                    $item['sourceUrl'] ?? null,
                    $item['url'] ?? null,
                ]),
                'raw' => $raw,
            ];
        }

        return $tasks;
    }

    private function taskItems(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        foreach ([
            $data['tasks'] ?? null,
            $data['available_tasks'] ?? null,
            $data['availableTasks'] ?? null,
            $data['data']['tasks'] ?? null,
            $data['data']['available_tasks'] ?? null,
            $data['data']['availableTasks'] ?? null,
            $data['data'] ?? null,
        ] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return $this->isList($data) ? $data : [];
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function extractJobId(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach (['job_id', 'jobId', 'jobID', 'crawler_job_id', 'crawlerJobId'] as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        foreach (['data', 'job', 'result', 'task'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $jobId = $this->extractJobId($data[$key]);
                if ($jobId !== null) {
                    return $jobId;
                }
            }
        }

        return null;
    }

    /**
     * @throws JsonException
     */
    private function decodeJsonResponse(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new JsonException('Expected JSON object response.');
        }

        return $data;
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
            return 'Crawler request failed with HTTP '.$status.': '.$this->formatFastApiDetail($data['detail']);
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['error']) && is_scalar($data['error']) && trim((string) $data['error']) !== '') {
            return (string) $data['error'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }

    private function formatFastApiDetail(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (! is_array($detail)) {
            return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
        }

        $messages = [];
        foreach ($detail as $item) {
            if (! is_array($item)) {
                continue;
            }

            $location = $item['loc'] ?? [];
            $path = is_array($location) ? implode('.', array_map('strval', $location)) : (string) $location;
            $message = is_scalar($item['msg'] ?? null) ? (string) $item['msg'] : 'validation error';
            $messages[] = $path !== '' ? "{$path}: {$message}" : $message;
        }

        return $messages === []
            ? (json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown validation error')
            : implode('; ', $messages);
    }
}
