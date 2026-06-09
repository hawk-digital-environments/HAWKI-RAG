<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofValueResolver
{
    public function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function stageFromStatus(array $status, string $stage): array
    {
        $stageStatus = $status['stages'][$stage] ?? [];

        return is_array($stageStatus) ? $stageStatus : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function stageRow(array $databaseState, string $stage): array
    {
        foreach (($databaseState['pipelineStageStates'] ?? []) as $row) {
            if (is_array($row) && ($row['stage'] ?? null) === $stage) {
                return $row;
            }
        }

        return [];
    }
}
