<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineProofMetadataBuilder
{
    public function __construct(
        private PipelineProofValueResolver $values,
    ) {
    }

    public function datasetPath(PipelineProofRepository $proofs, string $jobId, array $finalStatus): ?string
    {
        $fromStatus = $this->values->firstString([
            $finalStatus['datasetPath'] ?? null,
            $finalStatus['stages']['convert']['datasetPath'] ?? null,
            $finalStatus['stages']['scrape']['datasetPath'] ?? null,
        ]);

        return $fromStatus ?? $proofs->datasetPathForJob($jobId);
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
        $pipelineJob = is_array($databaseState['pipelineJob'] ?? null) ? $databaseState['pipelineJob'] : [];
        $scrapeProcess = is_array($databaseState['scrapeProcess'] ?? null) ? $databaseState['scrapeProcess'] : [];
        $request = is_array($scrapeProcess['request'] ?? null) ? $scrapeProcess['request'] : [];

        return [
            'job_id' => $jobId,
            'source_url' => $this->values->firstString([
                $io->option('source-url'),
                $pipelineJob['source_url'] ?? null,
                $scrapeProcess['url'] ?? null,
                $request['url'] ?? null,
            ]),
            'requested_output_dir' => $this->values->firstString([
                $io->option('requested-output-dir'),
                $request['output_dir'] ?? null,
                $request['outputDir'] ?? null,
            ]),
            'actual_dataset_path' => $datasetPath,
            'pipeline_status_endpoint' => "/pipeline/status/{$jobId}",
            'captured_at' => $capturedAt,
            'capture_started_at' => $startedAt->toIso8601String(),
            'final_status_updated_at' => $finalStatus['updatedAt'] ?? null,
        ];
    }
}
