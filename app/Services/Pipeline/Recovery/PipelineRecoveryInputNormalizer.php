<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryInputNormalizer
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{limit: int, task_id: string|null, dataset_id: string|null}
     */
    public function filters(array $filters): array
    {
        return [
            'limit' => max(1, min(500, (int) ($filters['limit'] ?? 200))),
            'task_id' => $this->stringValue($filters['task_id'] ?? $filters['taskId'] ?? null),
            'dataset_id' => $this->stringValue($filters['dataset_id'] ?? $filters['datasetId'] ?? null),
        ];
    }

    /**
     * @param  list<mixed>  $jobIds
     * @return list<string>
     */
    public function jobIds(array $jobIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $jobId): ?string => $this->stringValue($jobId),
            $jobIds,
        ))));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
