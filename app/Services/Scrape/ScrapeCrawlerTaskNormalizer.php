<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeCrawlerTaskNormalizer
{
    public function __construct(
        private ScrapeTaskValueNormalizer $values,
    ) {
    }

    public function normalize(mixed $data): array
    {
        $tasks = [];
        foreach ($this->values->taskItems($data) as $key => $item) {
            $raw = $item;
            if (is_scalar($item)) {
                $item = ['id' => (string) $item, 'label' => (string) $item];
            }

            if (! is_array($item)) {
                continue;
            }

            $id = $this->values->firstScalar([
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
                'label' => $this->values->firstScalar([$item['label'] ?? null, $item['title'] ?? null, $item['name'] ?? null, $id]),
                'description' => $this->values->firstScalar([$item['description'] ?? null, $item['summary'] ?? null, $item['url'] ?? null]),
                'status' => $this->values->firstScalar([$item['status'] ?? null, $item['state'] ?? null]),
                'routingKey' => $this->values->firstScalar([$item['routing_key'] ?? null, $item['routingKey'] ?? null]),
                'profileId' => $this->values->firstScalar([$item['profile_id'] ?? null, $item['profileId'] ?? null]),
                'schedule' => $this->values->firstScalar([$item['schedule'] ?? null, $item['cron'] ?? null]),
                'type' => $this->values->firstScalar([$item['type'] ?? null, $item['kind'] ?? null]) ?? 'legacy',
                'source' => 'crawler-api',
                'primaryUrl' => $this->values->firstScalar([$item['source_url'] ?? null, $item['sourceUrl'] ?? null, $item['url'] ?? null]),
                'raw' => $raw,
            ];
        }

        return $tasks;
    }
}
