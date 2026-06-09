<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Models\JobProcessingState;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofWorkerEvidenceBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(array $databaseState): array
    {
        $rows = is_array($databaseState['jobProcessingState'] ?? null) ? $databaseState['jobProcessingState'] : [];
        $counts = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'table' => 'job_processing_state',
            'rowsFound' => count($rows),
            'statusCounts' => $counts,
            'completedRows' => $counts[JobProcessingState::STATUS_COMPLETED] ?? 0,
            'failedRows' => $counts[JobProcessingState::STATUS_FAILED] ?? 0,
            'rows' => $rows,
        ];
    }
}
