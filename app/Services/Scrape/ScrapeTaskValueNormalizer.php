<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeTaskValueNormalizer
{
    public function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== ''
        ));
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

    public function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
