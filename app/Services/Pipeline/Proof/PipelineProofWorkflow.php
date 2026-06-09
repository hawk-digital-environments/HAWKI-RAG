<?php

namespace App\Services\Pipeline\Proof;

use App\Models\JobProcessingState;
use App\Services\Pipeline\Status\PipelineStatusService;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use App\Support\PipelineExitCode;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Finder\Finder;
use Throwable;

class PipelineProofWorkflow
{
    private ConsoleWorkflowIO $io;
    private PipelineStatusService $statuses;

    public function __construct(
        private readonly PipelineProofMarkdownRenderer $markdown,
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(ConsoleWorkflowIO $io, PipelineProofRepository $proofs, PipelineStatusService $statuses): int
    {
        $this->io = $io;
        $this->statuses = $statuses;
        $jobId = trim((string) $this->argument('job_id'));
        if ($jobId === '') {
            $this->error('job_id is required.');
            return PipelineExitCode::VALIDATION_FAILURE;
        }

        $startedAt = Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $outputDir = $this->outputDirectory($jobId, $startedAt);
        $this->files->ensureDirectoryExists($outputDir);

        $snapshots = $this->captureSnapshots($jobId);
        $finalStatus = $this->latestStatusData($snapshots);
        $datasetPath = $this->datasetPath($proofs, $jobId, $finalStatus);
        $databaseState = $proofs->databaseState($jobId, $datasetPath);
        $conversionEvidence = $this->conversionEvidence($datasetPath, $finalStatus, $databaseState);
        $publishEvidence = $this->publishEvidence($finalStatus, $databaseState);
        $workerEvidence = $this->workerEvidence($databaseState);
        $logs = $this->collectLogs(
            $this->logTokens($jobId, $datasetPath, $databaseState, $conversionEvidence),
            $jobId,
            max(1, (int) $this->option('max-log-lines')),
        );

        $metadata = $this->jobMetadata($jobId, $datasetPath, $finalStatus, $databaseState, $startedAt);
        $finalProof = $this->finalProof($finalStatus, $databaseState);

        $proof = [
            'capturedAt' => $this->timestamp(),
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

        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'proof.json', $proof);
        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'final-status.json', $finalStatus);
        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'database-state.json', $databaseState);
        $this->writeJsonl($outputDir . DIRECTORY_SEPARATOR . 'status-snapshots.jsonl', $snapshots);
        $this->writeJsonl($outputDir . DIRECTORY_SEPARATOR . 'pipeline-stage-logs.jsonl', $logs['pipelineStageLogs']);
        $this->writeJsonl($outputDir . DIRECTORY_SEPARATOR . 'related-logs.jsonl', $logs['relatedLogs']);
        $this->files->put($outputDir . DIRECTORY_SEPARATOR . 'proof.md', $this->markdown->report($proof));

        $this->info("Pipeline proof saved to: {$outputDir}");
        if ($finalProof['allCompleted']) {
            $this->info('Final proof status: scrape, convert, and ingest are completed.');
        } else {
            $this->warn('Final proof status is not fully completed. Check proof.md for the exact stage states.');
        }

        return PipelineExitCode::SUCCESS;
    }

    private function outputDirectory(string $jobId, Carbon $startedAt): string
    {
        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            return $output;
        }

        return storage_path(
            'logs/pipeline-proofs/'
            . $this->safePathSegment($jobId)
            . '-'
            . $startedAt->format('Ymd_His')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captureSnapshots(string $jobId): array
    {
        $snapshots = [];
        $latest = $this->statusSnapshot($jobId, 'initial');
        $snapshots[] = $latest;

        if (!$this->option('watch')) {
            return $snapshots;
        }

        $interval = max(0.5, (float) $this->option('interval'));
        $timeout = max(1, (int) $this->option('timeout'));
        $deadline = microtime(true) + $timeout;
        $lastSignature = $this->statusSignature($latest);

        while (microtime(true) < $deadline) {
            if ($this->watchIsTerminal($latest['data'] ?? null)) {
                return $snapshots;
            }

            usleep((int) ($interval * 1_000_000));
            $next = $this->statusSnapshot($jobId, 'watch_transition');
            $nextSignature = $this->statusSignature($next);

            if ($nextSignature !== $lastSignature) {
                $snapshots[] = $next;
                $lastSignature = $nextSignature;
                $this->line($this->snapshotLine($next));
            }

            $latest = $next;
        }

        $latest['reason'] = 'watch_timeout';
        if ($this->statusSignature($latest) === $lastSignature) {
            $snapshots[] = $latest;
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusSnapshot(string $jobId, string $reason): array
    {
        try {
            $data = $this->statuses->show($jobId);

            return [
                'capturedAt' => $this->timestamp(),
                'reason' => $reason,
                'endpoint' => "/pipeline/status/{$jobId}",
                'success' => true,
                'data' => is_array($data) ? $data : [],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'capturedAt' => $this->timestamp(),
                'reason' => $reason,
                'endpoint' => "/pipeline/status/{$jobId}",
                'success' => false,
                'data' => [],
                'error' => [
                    'type' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function snapshotLine(array $snapshot): string
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];

        return sprintf(
            'snapshot %s | overall=%s | scrape=%s | convert=%s | ingest=%s',
            $snapshot['capturedAt'] ?? '',
            $data['status'] ?? 'unknown',
            $stages['scrape']['status'] ?? 'unknown',
            $stages['convert']['status'] ?? 'unknown',
            $stages['ingest']['status'] ?? 'unknown',
        );
    }

    private function statusSignature(array $snapshot): string
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];

        return $this->json([
            'success' => (bool) ($snapshot['success'] ?? false),
            'overall' => $data['status'] ?? null,
            'currentStage' => $data['currentStage'] ?? null,
            'scrape' => $this->stageSignature($stages['scrape'] ?? []),
            'convert' => $this->stageSignature($stages['convert'] ?? []),
            'ingest' => $this->stageSignature($stages['ingest'] ?? []),
            'error' => $snapshot['error']['message'] ?? null,
        ]);
    }

    /**
     * @param mixed $stage
     * @return array<string, mixed>
     */
    private function stageSignature(mixed $stage): array
    {
        $stage = is_array($stage) ? $stage : [];

        return [
            'status' => $stage['status'] ?? null,
            'counts' => $stage['counts'] ?? [],
            'retry' => $stage['retry'] ?? [],
            'errorCount' => is_array($stage['errors'] ?? null) ? count($stage['errors']) : 0,
        ];
    }

    private function watchIsTerminal(mixed $status): bool
    {
        if (!is_array($status)) {
            return false;
        }

        $proof = $this->finalProof($status, []);
        if ($proof['allCompleted']) {
            return true;
        }

        $stageStatuses = array_filter([
            $proof['scrapeStatus'],
            $proof['convertStatus'],
            $proof['ingestStatus'],
        ]);

        if (array_intersect($stageStatuses, ['running', 'processing', 'received', 'pending'])) {
            return false;
        }

        return (bool) array_intersect($stageStatuses, ['failed', 'partial', 'skipped']);
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     * @return array<string, mixed>
     */
    private function latestStatusData(array $snapshots): array
    {
        for ($index = count($snapshots) - 1; $index >= 0; $index--) {
            if (($snapshots[$index]['success'] ?? false) && is_array($snapshots[$index]['data'] ?? null)) {
                return $snapshots[$index]['data'];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobMetadata(
        string $jobId,
        ?string $datasetPath,
        array $finalStatus,
        array $databaseState,
        Carbon $startedAt,
    ): array {
        $pipelineJob = is_array($databaseState['pipelineJob'] ?? null) ? $databaseState['pipelineJob'] : [];
        $scrapeProcess = is_array($databaseState['scrapeProcess'] ?? null) ? $databaseState['scrapeProcess'] : [];
        $request = is_array($scrapeProcess['request'] ?? null) ? $scrapeProcess['request'] : [];

        return [
            'job_id' => $jobId,
            'source_url' => $this->firstString([
                $this->option('source-url'),
                $pipelineJob['source_url'] ?? null,
                $scrapeProcess['url'] ?? null,
                $request['url'] ?? null,
            ]),
            'requested_output_dir' => $this->firstString([
                $this->option('requested-output-dir'),
                $request['output_dir'] ?? null,
                $request['outputDir'] ?? null,
            ]),
            'actual_dataset_path' => $datasetPath,
            'pipeline_status_endpoint' => "/pipeline/status/{$jobId}",
            'captured_at' => $this->timestamp(),
            'capture_started_at' => $startedAt->toIso8601String(),
            'final_status_updated_at' => $finalStatus['updatedAt'] ?? null,
        ];
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

    private function datasetPath(PipelineProofRepository $proofs, string $jobId, array $finalStatus): ?string
    {
        $fromStatus = $this->firstString([
            $finalStatus['datasetPath'] ?? null,
            $finalStatus['stages']['convert']['datasetPath'] ?? null,
            $finalStatus['stages']['scrape']['datasetPath'] ?? null,
        ]);
        if ($fromStatus !== null) {
            return $fromStatus;
        }

        $fromJob = $proofs->datasetPathForJob($jobId);
        if ($fromJob !== null) {
            return $fromJob;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversionEvidence(?string $datasetPath, array $finalStatus, array $databaseState): array
    {
        $convertStage = $this->stageFromStatus($finalStatus, 'convert');
        $convertRow = $this->stageRow($databaseState, 'convert');
        $metadataFiles = $this->convertedMetadataFiles($datasetPath);
        $failures = $this->conversionFailures($datasetPath);
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
            'failedConversionReport' => $failures,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertedMetadataFiles(?string $datasetPath): array
    {
        if ($datasetPath === null || !is_dir($datasetPath)) {
            return [];
        }

        $files = [];
        foreach ($this->filesUnder($datasetPath) as $file) {
            if ($file->getFilename() !== 'conversion_meta.json') {
                continue;
            }

            $meta = json_decode((string) @file_get_contents($file->getPathname()), true);
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
     * @return array<int, array<string, mixed>>
     */
    private function conversionFailures(?string $datasetPath): array
    {
        $reportPath = storage_path('logs/failed_conversion.json');
        if ($datasetPath === null || !is_file($reportPath)) {
            return [];
        }

        $report = json_decode((string) @file_get_contents($reportPath), true);
        if (!is_array($report) || !is_array($report['failures'] ?? null)) {
            return [];
        }

        return array_values(array_filter($report['failures'], function ($failure) use ($datasetPath): bool {
            $path = is_array($failure) ? (string) ($failure['file_local_path'] ?? $failure['pdf_local_path'] ?? '') : '';

            return $path !== '' && str_starts_with($path, rtrim($datasetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        }));
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
    private function publishEvidence(array $finalStatus, array $databaseState): array
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
    private function workerEvidence(array $databaseState): array
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
    private function finalProof(array $finalStatus, array $databaseState): array
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

    /**
     * @return array<int, string>
     */
    private function logTokens(string $jobId, ?string $datasetPath, array $databaseState, array $conversionEvidence): array
    {
        $tokens = [$jobId];
        if ($datasetPath !== null && $datasetPath !== '') {
            $tokens[] = $datasetPath;
        }

        foreach (($databaseState['jobProcessingState'] ?? []) as $row) {
            foreach (['job_id', 'input_path', 'output_path', 'trace_id'] as $key) {
                if (is_array($row) && is_scalar($row[$key] ?? null) && trim((string) $row[$key]) !== '') {
                    $tokens[] = trim((string) $row[$key]);
                }
            }
        }

        foreach (($conversionEvidence['convertedMetadataFiles'] ?? []) as $meta) {
            foreach (['converted_id', 'source_file', 'output_dir'] as $key) {
                if (is_array($meta) && is_scalar($meta[$key] ?? null) && trim((string) $meta[$key]) !== '') {
                    $tokens[] = trim((string) $meta[$key]);
                }
            }
        }

        return array_values(array_unique(array_filter($tokens, fn (string $token) => $token !== '')));
    }

    /**
     * @return array{pipelineStageLogs:array<int,array<string,mixed>>,relatedLogs:array<int,array<string,mixed>>,filesScanned:array<int,string>}
     */
    private function collectLogs(array $tokens, string $jobId, int $maxLines): array
    {
        $pipelineStageLogs = [];
        $relatedLogs = [];
        $filesScanned = [];

        foreach ($this->logFiles() as $path) {
            $filesScanned[] = $path;
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (!$this->lineMatchesTokens($line, $tokens)) {
                        continue;
                    }

                    $entry = $this->logEntry($path, $line);
                    if ($this->isPipelineStageLogForJob($entry, $jobId)) {
                        $pipelineStageLogs[] = $entry;
                    }

                    if (count($relatedLogs) < $maxLines) {
                        $relatedLogs[] = $entry;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return [
            'pipelineStageLogs' => $pipelineStageLogs,
            'relatedLogs' => $relatedLogs,
            'filesScanned' => $filesScanned,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function logFiles(): array
    {
        $paths = [];
        foreach (['comm_logs.json', 'laravel.log'] as $file) {
            $path = storage_path("logs/{$file}");
            if (is_file($path)) {
                $paths[] = $path;
            }
        }

        foreach ($this->files->glob(storage_path('logs/laravel-*.log')) ?: [] as $path) {
            if (is_file($path)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function lineMatchesTokens(string $line, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($line, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function logEntry(string $path, string $line): array
    {
        $trimmed = rtrim($line, "\r\n");
        $decoded = json_decode($trimmed, true);

        return [
            'file' => $path,
            'decoded' => is_array($decoded) ? $decoded : null,
            'raw' => $trimmed,
        ];
    }

    private function isPipelineStageLogForJob(array $entry, string $jobId): bool
    {
        $decoded = is_array($entry['decoded'] ?? null) ? $entry['decoded'] : [];
        $context = is_array($decoded['context'] ?? null) ? $decoded['context'] : [];

        $isPipelineStage = ($decoded['message'] ?? null) === 'pipeline.stage'
            || ($context['event'] ?? null) === 'pipeline.stage';

        return $isPipelineStage && (string) ($context['job_id'] ?? '') === $jobId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->files->put($path, $this->json($data) . PHP_EOL);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeJsonl(string $path, array $rows): void
    {
        $lines = array_map(fn (array $row) => $this->json($row), $rows);
        $this->files->put($path, implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL));
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    private function safePathSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?: 'pipeline-job';

        return trim($safe, '-') ?: 'pipeline-job';
    }

    private function argument(string $name): mixed
    {
        return $this->io->argument($name);
    }

    private function option(string $name): mixed
    {
        return $this->io->option($name);
    }

    private function line(string $message): void
    {
        $this->io->line($message);
    }

    private function info(string $message): void
    {
        $this->io->info($message);
    }

    private function error(string $message): void
    {
        $this->io->error($message);
    }

    private function warn(string $message): void
    {
        $this->io->warn($message);
    }
}
