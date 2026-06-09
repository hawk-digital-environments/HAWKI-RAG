<?php

declare(strict_types=1);

namespace App\Services\Scrape;

class ScrapeTaskNormalizer
{
    public function normalizeTaskUiList(array $profilesResult, array $tasksResult): array
    {
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
            'tasks' => $tasks,
            'profiles' => array_values($profiles),
            'storedTasks' => $storedTasks,
        ];
    }

    public function taskUiProfileToUi(mixed $entry): ?array
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
                $hostEntrypoints[] = ['type' => 'host', 'src' => $host];
            }
        }

        $entrypoints = $hostEntrypoints;
        $sitemapBase = $this->firstScalar([$sitemap['base_url'] ?? null]);
        if ($sitemapBase !== null) {
            $entrypoints[] = ['type' => 'sitemap', 'src' => $sitemapBase];
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

    public function taskUiStartPayload(array $task, array $profile, array $options): array
    {
        $firstHost = $this->taskUiFirstEntrypoint($profile, 'host');
        $firstSitemap = $this->taskUiFirstEntrypoint($profile, 'sitemap');
        $jobId = $this->firstScalar([$options['job_id'] ?? null, $options['jobId'] ?? null])
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

    public function normalizeCrawlerTasks(mixed $data): array
    {
        $tasks = [];
        foreach ($this->taskItems($data) as $key => $item) {
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
                'label' => $this->firstScalar([$item['label'] ?? null, $item['title'] ?? null, $item['name'] ?? null, $id]),
                'description' => $this->firstScalar([$item['description'] ?? null, $item['summary'] ?? null, $item['url'] ?? null]),
                'status' => $this->firstScalar([$item['status'] ?? null, $item['state'] ?? null]),
                'routingKey' => $this->firstScalar([$item['routing_key'] ?? null, $item['routingKey'] ?? null]),
                'profileId' => $this->firstScalar([$item['profile_id'] ?? null, $item['profileId'] ?? null]),
                'schedule' => $this->firstScalar([$item['schedule'] ?? null, $item['cron'] ?? null]),
                'type' => $this->firstScalar([$item['type'] ?? null, $item['kind'] ?? null]) ?? 'legacy',
                'source' => 'crawler-api',
                'primaryUrl' => $this->firstScalar([$item['source_url'] ?? null, $item['sourceUrl'] ?? null, $item['url'] ?? null]),
                'raw' => $raw,
            ];
        }

        return $tasks;
    }

    public function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function taskUiProfileEntries(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $profiles = $data['profiles'] ?? $data['data']['profiles'] ?? $data;

        return is_array($profiles) && $this->isList($profiles) ? $profiles : [];
    }

    private function normalizeTaskUiStoredTasks(mixed $data, array $profiles): array
    {
        $tasks = [];
        foreach ($this->taskItems($data) as $item) {
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

            $normalized[] = ['type' => $type, 'src' => $src];
        }

        return $normalized;
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
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== ''
        ));
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
}
