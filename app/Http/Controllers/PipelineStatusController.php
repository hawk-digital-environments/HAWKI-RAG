<?php

namespace App\Http\Controllers;

use App\Models\JobProcessingState;
use App\Models\ScrapeProcess;
use App\Services\ScrapeService\ScrapeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

class PipelineStatusController extends Controller
{
    public function __construct(
        private readonly ScrapeService $scrapeService,
    ) {
    }

    public function show(string $jobId): JsonResponse
    {
        $scrape = $this->scrapeStage($jobId);
        $datasetPath = $scrape['datasetPath'] ?? null;
        $convert = $this->convertStage($datasetPath);
        $ingest = $this->ingestStage($jobId, $datasetPath);

        return response()->json([
            'success' => true,
            'jobId' => $jobId,
            'datasetPath' => $datasetPath,
            'currentStage' => $this->currentStage($scrape, $convert, $ingest),
            'status' => $this->overallStatus($scrape, $convert, $ingest),
            'stages' => [
                'scrape' => $scrape,
                'convert' => $convert,
                'ingest' => $ingest,
            ],
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    private function scrapeStage(string $jobId): array
    {
        $process = null;
        if (Schema::hasTable('scrape_jobs')) {
            $query = ScrapeProcess::query();
            if (Schema::hasTable('scrape_statistics')) {
                $query->with(['stats']);
            }
            $process = $query->where('job_id', $jobId)->first();
        }

        $live = $this->scrapeService->getCrawlerStatus($jobId);
        $liveData = ($live['success'] ?? false) && is_array($live['data'] ?? null)
            ? $live['data']
            : [];

        $request = is_array($process?->request) ? $process->request : [];
        $stats = ($process && Schema::hasTable('scrape_statistics')) ? $process->stats : null;
        $datasetPath = $liveData['output_directory']
            ?? $request['output_dir']
            ?? $request['outputDir']
            ?? null;

        $errors = $this->arrayValue($stats?->errors);
        $warnings = $this->arrayValue($stats?->warnings);

        return [
            'status' => $liveData['status'] ?? $process?->stage ?? 'unknown',
            'message' => $liveData['message'] ?? null,
            'datasetPath' => $datasetPath,
            'startedAt' => $this->dateValue($liveData['started_at'] ?? null) ?? $this->dateValue($stats?->started_at),
            'completedAt' => $this->dateValue($liveData['completed_at'] ?? null) ?? $this->dateValue($stats?->completed_at),
            'counts' => [
                'pagesCrawled' => (int) ($liveData['pages_crawled'] ?? $stats?->completed_urls ?? 0),
                'totalPages' => (int) ($liveData['total_pages'] ?? $stats?->total_urls ?? $stats?->target_urls ?? 0),
                'completedUrls' => (int) ($stats?->completed_urls ?? 0),
                'failedUrls' => (int) ($stats?->failed_urls ?? 0),
                'elements' => ($process && Schema::hasTable('scraped_elements')) ? $process->elements()->count() : 0,
                'filesDownloaded' => (int) ($stats?->pdfs_downloaded ?? 0),
                'imagesDownloaded' => (int) ($stats?->images_downloaded ?? 0),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'source' => [
                'laravelFound' => $process !== null,
                'laravelTableAvailable' => Schema::hasTable('scrape_jobs'),
                'statisticsTableAvailable' => Schema::hasTable('scrape_statistics'),
                'elementsTableAvailable' => Schema::hasTable('scraped_elements'),
                'crawlerFound' => (bool) ($live['success'] ?? false),
                'crawlerStatus' => $live['status'] ?? null,
            ],
        ];
    }

    private function convertStage(?string $datasetPath): array
    {
        if (!$datasetPath) {
            return $this->emptyStage('unknown', 'No dataset path available yet.');
        }

        $resolvedPath = realpath($datasetPath);
        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            return $this->emptyStage('unknown', 'Dataset path does not exist yet.', [
                'datasetPath' => $datasetPath,
            ]);
        }

        $extensions = $this->supportedExtensions();
        $sourceCount = 0;
        $convertedCount = 0;
        $convertedAt = [];

        foreach ($this->filesUnder($resolvedPath) as $file) {
            $path = $file->getPathname();
            if ($this->isConvertedOutputPath($path)) {
                if ($file->getFilename() === 'conversion_meta.json') {
                    $convertedCount++;
                    $meta = json_decode((string) @file_get_contents($path), true);
                    if (is_array($meta) && !empty($meta['converted_at'])) {
                        $convertedAt[] = (string) $meta['converted_at'];
                    }
                }
                continue;
            }

            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $sourceCount++;
            }
        }

        $failures = $this->conversionFailuresFor($resolvedPath);
        $failedCount = count($failures);

        return [
            'status' => $this->convertStatus($sourceCount, $convertedCount, $failedCount),
            'datasetPath' => $resolvedPath,
            'startedAt' => $convertedAt === [] ? null : min($convertedAt),
            'completedAt' => $convertedAt === [] ? null : max($convertedAt),
            'counts' => [
                'sourceFiles' => $sourceCount,
                'convertedFiles' => $convertedCount,
                'failedFiles' => $failedCount,
            ],
            'supportedExtensions' => $extensions,
            'errors' => $failures,
            'retry' => [
                'retryCount' => null,
                'maxRetries' => (int) config('file_converter.retries', 3),
            ],
        ];
    }

    private function ingestStage(string $jobId, ?string $datasetPath): array
    {
        if (!Schema::hasTable('job_processing_state')) {
            return $this->emptyStage('unknown', 'Ingest state table is not available.');
        }

        $query = JobProcessingState::query()
            ->where('stage', JobProcessingState::STAGE_RAG_INGESTION)
            ->where(function ($query) use ($jobId, $datasetPath): void {
                $query->where('job_id', $jobId);

                $resolved = $datasetPath ? realpath($datasetPath) : false;
                $paths = array_values(array_filter([
                    is_string($datasetPath) && $datasetPath !== '' ? $datasetPath : null,
                    is_string($resolved) ? $resolved : null,
                ]));

                foreach ($paths as $path) {
                    $like = $this->escapeLike($path) . '%';
                    $query->orWhere('input_path', 'like', $like)
                        ->orWhere('output_path', 'like', $like);
                }
            });

        $states = $query->orderByDesc('updated_at')->get();
        if ($states->isEmpty()) {
            return $this->emptyStage('unknown', 'No ingest state found yet.');
        }

        $counts = $states->countBy('status')->all();
        $latest = $states->first();
        $errors = $states
            ->filter(fn (JobProcessingState $state) => filled($state->error_message))
            ->map(fn (JobProcessingState $state) => [
                'jobId' => $state->job_id,
                'status' => $state->status,
                'errorType' => $state->error_type,
                'message' => $state->error_message,
                'retryCount' => $state->retry_count,
                'maxRetries' => $state->max_retries,
                'updatedAt' => $this->dateValue($state->updated_at),
            ])
            ->values()
            ->all();

        return [
            'status' => $this->ingestStatus($counts),
            'startedAt' => $this->dateValue($states->min('processing_started_at') ?? $states->min('first_received_at')),
            'completedAt' => $this->dateValue($states->max('completed_at')),
            'counts' => [
                'total' => $states->count(),
                'received' => (int) ($counts[JobProcessingState::STATUS_RECEIVED] ?? 0),
                'processing' => (int) ($counts[JobProcessingState::STATUS_PROCESSING] ?? 0),
                'completed' => (int) ($counts[JobProcessingState::STATUS_COMPLETED] ?? 0),
                'failed' => (int) ($counts[JobProcessingState::STATUS_FAILED] ?? 0),
            ],
            'retry' => [
                'retryCount' => (int) ($latest?->retry_count ?? 0),
                'maxRetries' => (int) ($latest?->max_retries ?? 0),
            ],
            'errors' => $errors,
            'latest' => $latest ? [
                'jobId' => $latest->job_id,
                'status' => $latest->status,
                'inputPath' => $latest->input_path,
                'outputPath' => $latest->output_path,
                'updatedAt' => $this->dateValue($latest->updated_at),
            ] : null,
        ];
    }

    private function currentStage(array $scrape, array $convert, array $ingest): string
    {
        if (!in_array($ingest['status'], ['unknown', 'pending', 'skipped', 'completed'], true)) {
            return 'ingest';
        }
        if (!in_array($convert['status'], ['unknown', 'pending', 'skipped', 'completed'], true)) {
            return 'convert';
        }
        if (!in_array($scrape['status'], ['unknown', 'completed'], true)) {
            return 'scrape';
        }
        if ($ingest['status'] === 'completed') {
            return 'ingest';
        }
        if (in_array($convert['status'], ['completed', 'skipped'], true) && in_array($ingest['status'], ['unknown', 'pending'], true)) {
            return 'ingest';
        }
        if ($scrape['status'] === 'completed' && in_array($convert['status'], ['unknown', 'pending', 'skipped'], true)) {
            return 'convert';
        }
        if ($convert['status'] === 'completed') {
            return 'convert';
        }

        return 'scrape';
    }

    private function overallStatus(array $scrape, array $convert, array $ingest): string
    {
        $statuses = [$scrape['status'], $convert['status'], $ingest['status']];
        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }
        if (in_array('partial', $statuses, true)) {
            return 'partial';
        }
        if (in_array('running', $statuses, true) || in_array('processing', $statuses, true) || in_array('received', $statuses, true)) {
            return 'running';
        }
        if ($scrape['status'] === 'completed' && $convert['status'] === 'completed' && $ingest['status'] === 'completed') {
            return 'completed';
        }

        return 'partial';
    }

    private function convertStatus(int $sourceCount, int $convertedCount, int $failedCount): string
    {
        if ($sourceCount === 0) {
            return 'skipped';
        }
        if ($failedCount > 0 && $convertedCount > 0) {
            return 'partial';
        }
        if ($failedCount > 0) {
            return 'failed';
        }
        if ($convertedCount >= $sourceCount) {
            return 'completed';
        }
        if ($convertedCount > 0) {
            return 'partial';
        }

        return 'pending';
    }

    private function ingestStatus(array $counts): string
    {
        $received = (int) ($counts[JobProcessingState::STATUS_RECEIVED] ?? 0);
        $processing = (int) ($counts[JobProcessingState::STATUS_PROCESSING] ?? 0);
        $completed = (int) ($counts[JobProcessingState::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($counts[JobProcessingState::STATUS_FAILED] ?? 0);

        if ($processing > 0) {
            return 'processing';
        }
        if ($received > 0) {
            return 'received';
        }
        if ($failed > 0 && $completed > 0) {
            return 'partial';
        }
        if ($failed > 0) {
            return 'failed';
        }
        if ($completed > 0) {
            return 'completed';
        }

        return 'unknown';
    }

    private function emptyStage(string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'startedAt' => null,
            'completedAt' => null,
            'counts' => [],
            'errors' => [],
            'retry' => [
                'retryCount' => null,
                'maxRetries' => null,
            ],
        ], $extra);
    }

    private function supportedExtensions(): array
    {
        $extensions = config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']);
        if (!is_array($extensions)) {
            return ['pdf', 'doc', 'docx'];
        }

        $extensions = array_values(array_filter(
            array_map(static fn ($extension) => is_scalar($extension) ? ltrim(strtolower(trim((string) $extension)), '.') : '', $extensions),
            static fn ($extension) => $extension !== ''
        ));

        return $extensions ?: ['pdf', 'doc', 'docx'];
    }

    private function filesUnder(string $path): Finder
    {
        return Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($path);
    }

    private function isConvertedOutputPath(string $path): bool
    {
        return str_contains(str_replace('\\', '/', $path), '/converted_');
    }

    private function conversionFailuresFor(string $datasetPath): array
    {
        $reportPath = storage_path('logs/failed_conversion.json');
        if (!is_file($reportPath)) {
            return [];
        }

        $report = json_decode((string) @file_get_contents($reportPath), true);
        if (!is_array($report) || !is_array($report['failures'] ?? null)) {
            return [];
        }

        return array_values(array_filter($report['failures'], function ($failure) use ($datasetPath): bool {
            $path = is_array($failure) ? (string) ($failure['file_local_path'] ?? $failure['pdf_local_path'] ?? '') : '';
            return $path !== '' && str_starts_with($path, $datasetPath . DIRECTORY_SEPARATOR);
        }));
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
