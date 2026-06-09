<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Models\JobProcessingState;
use App\Services\FileConverter\ConversionFailureReportReader;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use App\Support\PipelineExitCode;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Symfony\Component\Finder\Finder;

#[Singleton]
readonly class PipelineProofEvidenceBuilder
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
        private ConversionFailureReportReader $failures,
    ) {
    }

    public function datasetPath(PipelineProofRepository $proofs, string $jobId, array $finalStatus): ?string
    {
        $fromStatus = $this->firstString([
            $finalStatus['datasetPath'] ?? null,
            $finalStatus['stages']['convert']['datasetPath'] ?? null,
            $finalStatus['stages']['scrape']['datasetPath'] ?? null,
        ]);
        if ($fromStatus !== null) {
            return $fromStatus;
        }

        return $proofs->datasetPathForJob($jobId);
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
            'source_url' => $this->firstString([
                $io->option('source-url'),
                $pipelineJob['source_url'] ?? null,
                $scrapeProcess['url'] ?? null,
                $request['url'] ?? null,
            ]),
            'requested_output_dir' => $this->firstString([
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

    /**
     * @return array<string, mixed>
     */
    public function conversionEvidence(?string $datasetPath, array $finalStatus, array $databaseState): array
    {
        $convertStage = $this->stageFromStatus($finalStatus, 'convert');
        $convertRow = $this->stageRow($databaseState, 'convert');
        $metadataFiles = $this->convertedMetadataFiles($datasetPath);
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
     * @return array<string, mixed>
     */
    public function publishEvidence(array $finalStatus, array $databaseState): array
    {
        $ingestStage = $this->stageFromStatus($finalStatus, 'ingest');
        $ingestRow = $this->stageRow($databaseState, 'ingest');
        $metadata = is_array($ingestStage['metadata'] ?? null)
            ? $ingestStage['metadata']
            : (is_array($ingestRow['metadata'] ?? null) ? $ingestRow['metadata'] : []);

        return [
            'publisher' => $metadata['publisher'] ?? null,
            'folder' => $metadata['folder'] ?? ($ingestRow['metadata']['folder'] ?? null),
            'documentsPublished' => $ingestStage['counts']['total'] ?? $ingestRow['counts']['total'] ?? null,
            'routingKey' => $this->config->get('communication.rabbitmq.pipeline_events.events.content_ingested', 'content.ingested'),
            'eventsExchange' => $this->config->get('communication.rabbitmq.pipeline_events.exchange', 'pipeline.events'),
            'exitCode' => $metadata['exitCode'] ?? null,
            'status' => $ingestStage['status'] ?? $ingestRow['status'] ?? 'unknown',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workerEvidence(array $databaseState): array
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

    /**
     * @return array<string, mixed>
     */
    public function finalProof(array $finalStatus, array $databaseState): array
    {
        $scrape = $this->stageFromStatus($finalStatus, 'scrape') ?: $this->stageRow($databaseState, 'scrape');
        $convert = $this->stageFromStatus($finalStatus, 'convert') ?: $this->stageRow($databaseState, 'convert');
        $ingest = $this->stageFromStatus($finalStatus, 'ingest') ?: $this->stageRow($databaseState, 'ingest');

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

    /**
     * @return list<array<string, mixed>>
     */
    private function convertedMetadataFiles(?string $datasetPath): array
    {
        if ($datasetPath === null || ! $this->files->isDirectory($datasetPath)) {
            return [];
        }

        $files = [];
        foreach ($this->filesUnder($datasetPath) as $file) {
            if ($file->getFilename() !== 'conversion_meta.json') {
                continue;
            }

            $meta = json_decode($this->files->get($file->getPathname()), true);
            $files[] = [
                'path' => $file->getPathname(),
                'pipeline_job_id' => is_array($meta) ? ($meta['pipeline_job_id'] ?? null) : null,
                'converted_id' => is_array($meta) ? ($meta['converted_id'] ?? null) : null,
                'source_file' => is_array($meta) ? ($meta['source_file'] ?? $meta['source_pdf'] ?? null) : null,
                'output_dir' => is_array($meta) ? ($meta['output_dir'] ?? null) : null,
                'files' => is_array($meta) ? ($meta['files'] ?? []) : [],
                'converted_at' => is_array($meta) ? ($meta['converted_at'] ?? null) : null,
            ];
        }

        return $files;
    }

    private function filesUnder(string $path): Finder
    {
        return Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($path);
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

    /**
     * @return array<string, mixed>
     */
    private function stageFromStatus(array $status, string $stage): array
    {
        $stageStatus = $status['stages'][$stage] ?? [];

        return is_array($stageStatus) ? $stageStatus : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stageRow(array $databaseState, string $stage): array
    {
        foreach (($databaseState['pipelineStageStates'] ?? []) as $row) {
            if (is_array($row) && ($row['stage'] ?? null) === $stage) {
                return $row;
            }
        }

        return [];
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
