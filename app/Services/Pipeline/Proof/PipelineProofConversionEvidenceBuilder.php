<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\FileConverter\ConversionFailureReportReader;
use App\Support\PipelineExitCode;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofConversionEvidenceBuilder
{
    public function __construct(
        private PipelineProofValueResolver $values,
        private PipelineProofConversionMetadataReader $metadata,
        private ConversionFailureReportReader $failures,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $datasetPath, array $finalStatus, array $databaseState): array
    {
        $convertStage = $this->values->stageFromStatus($finalStatus, 'convert');
        $convertRow = $this->values->stageRow($databaseState, 'convert');
        $metadataFiles = $this->metadata->read($datasetPath);
        $status = (string) ($convertStage['status'] ?? $convertRow['status'] ?? 'unknown');
        $exitCode = $convertRow['metadata']['exitCode'] ?? null;
        $exitCodeSource = $exitCode !== null ? 'pipeline_stage_states.metadata.exitCode' : null;

        if ($exitCode === null) {
            [$exitCode, $exitCodeSource] = $this->inferredExitCode($status);
        }

        return [
            'datasetPath' => $datasetPath,
            'status' => $status,
            'counts' => $convertStage['counts'] ?? $convertRow['counts'] ?? [],
            'exitCode' => $exitCode,
            'exitCodeSource' => $exitCodeSource,
            'convertedMetadataFiles' => $metadataFiles,
            'convertedMetadataCount' => count($metadataFiles),
            'failedConversionReport' => $this->failures->failuresFor($datasetPath),
        ];
    }

    /**
     * @return array{0:int|null,1:string|null}
     */
    private function inferredExitCode(string $status): array
    {
        return match ($status) {
            'completed' => [PipelineExitCode::SUCCESS, 'inferred from convert status'],
            'partial', 'skipped' => [PipelineExitCode::PARTIAL_SUCCESS, 'inferred from convert status'],
            'failed' => [PipelineExitCode::RUNTIME_FAILURE, 'inferred from convert status'],
            default => [null, null],
        };
    }
}
