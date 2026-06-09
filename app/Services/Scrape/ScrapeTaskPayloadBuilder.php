<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeTaskPayloadBuilder
{
    public function __construct(
        private ScrapeTaskProfileNormalizer $profiles,
        private ScrapeTaskValueNormalizer $values,
    ) {
    }

    public function storedTasks(mixed $data, array $profiles): array
    {
        $tasks = [];
        foreach ($this->values->taskItems($data) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->values->firstScalar([$item['id'] ?? null]);
            $profileId = $this->values->firstScalar([$item['profileId'] ?? null, $item['profile_id'] ?? null]);
            if ($id === null || $profileId === null) {
                continue;
            }

            $profile = $profiles[$profileId] ?? null;
            $tasks[] = $this->task([
                'id' => $id,
                'name' => $this->values->firstScalar([$item['name'] ?? null, $id]) ?? $id,
                'profileId' => $profileId,
                'profileName' => $profile['name'] ?? $profileId,
                'schedule' => $this->values->firstScalar([$item['schedule'] ?? null]),
                'containerPath' => $profile['containerPath'] ?? null,
                'entrypoints' => $profile['entrypoints'] ?? [],
                'settings' => $profile ? $this->settings($profile) : [],
                'raw' => $item,
            ]);
        }

        return $tasks;
    }

    public function taskForProfile(array $profile): array
    {
        return $this->task([
            'id' => 'manual-'.$profile['id'],
            'name' => $profile['name'].' Manual Crawl',
            'profileId' => $profile['id'],
            'profileName' => $profile['name'],
            'schedule' => null,
            'containerPath' => $profile['containerPath'] ?? null,
            'entrypoints' => $profile['entrypoints'] ?? [],
            'settings' => $this->settings($profile),
            'raw' => $profile['raw'] ?? $profile,
        ]);
    }

    private function task(array $task): array
    {
        $schedule = $this->values->firstScalar([$task['schedule'] ?? null]);
        $profileName = $this->values->firstScalar([$task['profileName'] ?? null, $task['profileId'] ?? null]);
        $entrypoints = $this->profiles->entrypoints($task['entrypoints'] ?? []);
        $firstHost = $this->profiles->firstEntrypoint(['entrypoints' => $entrypoints], 'host');
        $firstSitemap = $this->profiles->firstEntrypoint(['entrypoints' => $entrypoints], 'sitemap');
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
            'containerPath' => $this->values->firstScalar([$task['containerPath'] ?? null]),
            'entrypoints' => $entrypoints,
            'settings' => is_array($task['settings'] ?? null) ? $task['settings'] : [],
            'raw' => $task['raw'] ?? $task,
        ];
    }

    private function settings(array $profile): array
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
}
