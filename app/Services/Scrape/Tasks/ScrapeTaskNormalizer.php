<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeTaskNormalizer
{
    public function __construct(
        private ScrapeTaskProfileNormalizer $profiles,
        private ScrapeTaskPayloadBuilder $payloads,
        private ScrapeCrawlerTaskNormalizer $crawlerTasks,
        private ScrapeTaskStartPayloadBuilder $startPayloads,
        private ScrapeTaskValueNormalizer $values,
    ) {
    }

    public function normalizeTaskUiList(array $profilesResult, array $tasksResult): array
    {
        $profileEntries = $this->profiles->profileEntries($profilesResult['data'] ?? []);
        $profiles = [];
        foreach ($profileEntries as $entry) {
            $profile = $this->taskUiProfileToUi($entry);
            if ($profile !== null) {
                $profiles[$profile['id']] = $profile;
            }
        }

        $storedTasks = ($tasksResult['success'] ?? false)
            ? $this->payloads->storedTasks($tasksResult['data'] ?? [], $profiles)
            : [];
        $storedIds = array_fill_keys(array_map(static fn (array $task): string => $task['id'], $storedTasks), true);

        $defaultTasks = [];
        foreach ($profiles as $profile) {
            $task = $this->payloads->taskForProfile($profile);
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
        return $this->profiles->toUi($entry);
    }

    public function taskUiStartPayload(array $task, array $profile, array $options): array
    {
        return $this->startPayloads->build($task, $profile, $options);
    }

    public function normalizeCrawlerTasks(mixed $data): array
    {
        return $this->crawlerTasks->normalize($data);
    }

    public function firstScalar(array $values): ?string
    {
        return $this->values->firstScalar($values);
    }
}
