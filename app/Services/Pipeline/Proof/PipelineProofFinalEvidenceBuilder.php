<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofFinalEvidenceBuilder
{
    public function __construct(
        private PipelineProofValueResolver $values,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(array $finalStatus, array $databaseState): array
    {
        $scrape = $this->values->stageFromStatus($finalStatus, 'scrape') ?: $this->values->stageRow($databaseState, 'scrape');
        $convert = $this->values->stageFromStatus($finalStatus, 'convert') ?: $this->values->stageRow($databaseState, 'convert');
        $ingest = $this->values->stageFromStatus($finalStatus, 'ingest') ?: $this->values->stageRow($databaseState, 'ingest');

        $scrapeStatus = $scrape['status'] ?? null;
        $convertStatus = $convert['status'] ?? null;
        $ingestStatus = $ingest['status'] ?? null;

        return [
            'overallStatus' => $finalStatus['status'] ?? ($databaseState['pipelineJob']['status'] ?? null),
            'currentStage' => $finalStatus['currentStage'] ?? ($databaseState['pipelineJob']['current_stage'] ?? null),
            'scrapeStatus' => $scrapeStatus,
            'convertStatus' => $convertStatus,
            'ingestStatus' => $ingestStatus,
            'allCompleted' => $scrapeStatus === 'completed'
                && $convertStatus === 'completed'
                && $ingestStatus === 'completed'
                && (($finalStatus['status'] ?? null) === 'completed' || ($databaseState['pipelineJob']['status'] ?? null) === 'completed'),
            'documentCounts' => $finalStatus['documentCounts'] ?? [
                'total' => $databaseState['pipelineJob']['total_documents'] ?? null,
                'processed' => $databaseState['pipelineJob']['processed_documents'] ?? null,
                'failed' => $databaseState['pipelineJob']['failed_documents'] ?? null,
                'skipped' => $databaseState['pipelineJob']['skipped_documents'] ?? null,
            ],
        ];
    }
}
