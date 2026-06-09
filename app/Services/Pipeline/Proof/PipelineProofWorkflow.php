<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use App\Support\PipelineExitCode;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class PipelineProofWorkflow
{
    public function __construct(
        private readonly PipelineProofSnapshotCollector $snapshots,
        private readonly PipelineProofEvidenceBuilder $evidence,
        private readonly PipelineProofArtifactWriter $artifacts,
        private readonly PipelineProofLogCollector $logCollector,
        private readonly ClockInterface $clock = new Clock,
    ) {
    }

    public function run(ConsoleWorkflowIO $io, PipelineProofRepository $proofs): int
    {
        $jobId = trim((string) $io->argument('job_id'));
        if ($jobId === '') {
            $io->error('job_id is required.');

            return PipelineExitCode::VALIDATION_FAILURE;
        }

        $startedAt = Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $outputDir = $this->artifacts->outputDirectory($io, $jobId, $startedAt);

        $snapshots = $this->snapshots->capture($io, $jobId);
        $finalStatus = $this->snapshots->latestStatusData($snapshots);
        $datasetPath = $this->evidence->datasetPath($proofs, $jobId, $finalStatus);
        $databaseState = $proofs->databaseState($jobId, $datasetPath);
        $conversionEvidence = $this->evidence->conversionEvidence($datasetPath, $finalStatus, $databaseState);
        $publishEvidence = $this->evidence->publishEvidence($finalStatus, $databaseState);
        $workerEvidence = $this->evidence->workerEvidence($databaseState);
        $logs = $this->logCollector->collect(
            $this->logCollector->tokens($jobId, $datasetPath, $databaseState, $conversionEvidence),
            $jobId,
            max(1, (int) $io->option('max-log-lines')),
        );

        $capturedAt = $this->timestamp();
        $metadata = $this->evidence->metadata($io, $jobId, $datasetPath, $finalStatus, $databaseState, $startedAt, $capturedAt);
        $finalProof = $this->evidence->finalProof($finalStatus, $databaseState);

        $proof = [
            'capturedAt' => $capturedAt,
            'metadata' => $metadata,
            'statusSnapshots' => $snapshots,
            'pipelineStageLogs' => $logs['pipelineStageLogs'],
            'relatedLogs' => $logs['relatedLogs'],
            'logFilesScanned' => $logs['filesScanned'],
            'convert' => $conversionEvidence,
            'publish' => $publishEvidence,
            'rabbitmqWorker' => $workerEvidence,
            'databaseState' => $databaseState,
            'finalProof' => $finalProof,
        ];

        $this->artifacts->write(
            $outputDir,
            $proof,
            $finalStatus,
            $databaseState,
            $snapshots,
            $logs['pipelineStageLogs'],
            $logs['relatedLogs'],
        );

        $io->info("Pipeline proof saved to: {$outputDir}");
        if ($finalProof['allCompleted']) {
            $io->info('Final proof status: scrape, convert, and ingest are completed.');
        } else {
            $io->warn('Final proof status is not fully completed. Check proof.md for the exact stage states.');
        }

        return PipelineExitCode::SUCCESS;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
