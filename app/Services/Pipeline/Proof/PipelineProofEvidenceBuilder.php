<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineProofEvidenceBuilder
{
    public function __construct(
        private PipelineProofMetadataBuilder $metadata,
        private PipelineProofConversionEvidenceBuilder $conversion,
        private PipelineProofPublishEvidenceBuilder $publish,
        private PipelineProofWorkerEvidenceBuilder $workers,
        private PipelineProofFinalEvidenceBuilder $final,
    ) {
    }

    public function datasetPath(PipelineProofRepository $proofs, string $jobId, array $finalStatus): ?string
    {
        return $this->metadata->datasetPath($proofs, $jobId, $finalStatus);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(
        ConsoleWorkflowIO $io,
        string $jobId,
        ?string $datasetPath,
        array $finalStatus,
        array $databaseState,
        Carbon $startedAt,
        string $capturedAt,
    ): array {
        return $this->metadata->metadata($io, $jobId, $datasetPath, $finalStatus, $databaseState, $startedAt, $capturedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function conversionEvidence(?string $datasetPath, array $finalStatus, array $databaseState): array
    {
        return $this->conversion->build($datasetPath, $finalStatus, $databaseState);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishEvidence(array $finalStatus, array $databaseState): array
    {
        return $this->publish->build($finalStatus, $databaseState);
    }

    /**
     * @return array<string, mixed>
     */
    public function workerEvidence(array $databaseState): array
    {
        return $this->workers->build($databaseState);
    }

    /**
     * @return array<string, mixed>
     */
    public function finalProof(array $finalStatus, array $databaseState): array
    {
        return $this->final->build($finalStatus, $databaseState);
    }
}
