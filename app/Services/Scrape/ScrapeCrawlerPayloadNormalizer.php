<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeCrawlerPayloadNormalizer
{
    public function extractJobId(mixed $data): ?string
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

    public function taskItems(mixed $data): array
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

    public function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
