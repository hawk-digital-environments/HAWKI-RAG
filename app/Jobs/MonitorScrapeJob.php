<?php

namespace App\Jobs;

use App\Models\PipelineJob;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\PipelineLogger;
use App\Services\Pipeline\PipelineStateService;
use App\Services\ScrapeService\ScrapeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class MonitorScrapeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 240;
    public int $timeout = 120;

    public function __construct(
        public readonly string $jobId,
    ) {
        $this->onQueue('default');
    }

    public function handle(ScrapeService $scrapeService, PipelineStateService $pipelineState): void
    {
        if ($pipelineState->isStageCompleted($this->jobId, PipelineStateService::STAGE_CONVERT)) {
            return;
        }

        $result = $scrapeService->getCrawlerStatus($this->jobId);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $crawlerStatus = strtolower((string) ($data['status'] ?? ''));
        $datasetPath = (string) ($data['output_directory'] ?? '');

        if (!($result['success'] ?? false)) {
            if ($this->attempts() < 5) {
                $this->release(10);
                return;
            }

            $pipelineState->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'errors' => [[
                    'message' => $result['message'] ?? 'Unable to read Crawl4AI status.',
                    'status' => $result['status'] ?? null,
                    'updatedAt' => now()->toIso8601String(),
                ]],
                'metadata' => ['source' => 'MonitorScrapeJob'],
            ]);
            $this->failPipelineJob($result['message'] ?? 'Unable to read Crawl4AI status.', [
                'crawlerStatus' => $crawlerStatus ?: 'unknown',
                'status' => $result['status'] ?? null,
            ]);
            return;
        }

        $counts = [
            'totalPages' => (int) ($data['total_pages'] ?? 0),
            'pagesCrawled' => (int) ($data['pages_crawled'] ?? 0),
            'failedUrls' => (int) ($data['failed_urls'] ?? 0),
        ];

        if ($crawlerStatus === 'completed') {
            $pipelineState->completeStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'metadata' => [
                    'message' => $data['message'] ?? 'Crawl completed.',
                    'source' => 'MonitorScrapeJob',
                ],
            ]);

            PipelineLogger::success('pipeline', [
                'job_id' => $this->jobId,
                'pipeline_stage' => 'scrape_to_convert_trigger',
                'output_dir' => $datasetPath,
            ]);

            if ($datasetPath !== '') {
                $pipelineJob = PipelineJob::query()->where('job_id', $this->jobId)->first();
                if ($pipelineJob?->task_id) {
                    $pipelineJob->forceFill([
                        'job_type' => PipelineJob::TYPE_SCRAPE,
                        'status' => PipelineJob::STATUS_COMPLETED,
                        'local_path' => $datasetPath,
                        'completed_at' => now(),
                        'finished_at' => now(),
                        'metadata' => array_merge($pipelineJob->metadata ?? [], [
                            'source' => 'MonitorScrapeJob',
                            'crawlerStatus' => $crawlerStatus,
                        ]),
                    ])->save();

                    $this->publishScrapeCompletedEvents($pipelineJob, $datasetPath);
                }
            }
            return;
        }

        if (in_array($crawlerStatus, ['failed', 'cancelled', 'canceled', 'stopped'], true)) {
            $pipelineState->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'errors' => [[
                    'message' => $data['message'] ?? "Crawl ended with status {$crawlerStatus}.",
                    'updatedAt' => now()->toIso8601String(),
                ]],
                'metadata' => ['source' => 'MonitorScrapeJob'],
            ]);
            $this->failPipelineJob($data['message'] ?? "Crawl ended with status {$crawlerStatus}.", [
                'crawlerStatus' => $crawlerStatus,
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
            ]);
            return;
        }

        $pipelineState->updateStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
            'metadata' => [
                'crawlerStatus' => $crawlerStatus ?: 'unknown',
                'message' => $data['message'] ?? null,
                'source' => 'MonitorScrapeJob',
            ],
        ]);

        $this->release(10);
    }

    public function failed(Throwable $exception): void
    {
        $pipelineJob = PipelineJob::query()->where('job_id', $this->jobId)->first();
        if ($pipelineJob?->task_id) {
            $this->failPipelineJob($exception->getMessage(), [
                'error' => $exception->getMessage(),
            ], $exception);
        }

        app(PipelineStateService::class)->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
            'errors' => [[
                'message' => $exception->getMessage(),
                'type' => class_basename($exception),
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => 'MonitorScrapeJob'],
        ]);
    }

    private function publishScrapeCompletedEvents(PipelineJob $job, string $datasetPath): void
    {
        $bus = app(PipelineEventBus::class);
        $state = app(PipelineEventStateService::class);
        $task = $job->task;
        $basePayload = [
            'task_id' => $job->task_id,
            'job_id' => $job->job_id,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $job->source_url,
            'local_path' => $datasetPath,
            'content_hash' => $job->content_hash,
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($job->metadata ?? [], [
                'dataset_path' => $datasetPath,
                'source' => 'MonitorScrapeJob',
            ]),
        ];

        $scrapedEvent = PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, $basePayload);
        $state->upsertJob($scrapedEvent, PipelineJob::STATUS_COMPLETED, [
            'dataset_path' => $datasetPath,
        ]);
        $bus->publish(PipelineEvent::PAGE_SCRAPED, $scrapedEvent);

        foreach ($this->supportedFiles($datasetPath) as $path) {
            $hash = @hash_file('sha256', $path) ?: hash('sha256', $path);
            $bus->publish(PipelineEvent::FILE_DISCOVERED, [
                'task_id' => $job->task_id,
                'job_id' => 'convert_' . substr(hash('sha256', $job->task_id . '|' . $path), 0, 24),
                'parent_job_id' => $job->job_id,
                'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? null),
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => $job->source_url,
                'local_path' => $path,
                'content_hash' => $hash,
                'status' => PipelineJob::STATUS_PENDING,
                'metadata' => [
                    'source' => 'MonitorScrapeJob',
                    'dataset_path' => $datasetPath,
                ],
            ]);
        }
    }

    private function failPipelineJob(string $message, array $metadata = [], ?Throwable $exception = null): void
    {
        $pipelineJob = PipelineJob::query()->where('job_id', $this->jobId)->first();
        if (!$pipelineJob?->task_id) {
            return;
        }

        $task = $pipelineJob->task;
        $original = PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $pipelineJob->task_id,
            'job_id' => $pipelineJob->job_id,
            'parent_job_id' => $pipelineJob->parent_job_id,
            'dataset_id' => $task?->dataset_id ?? ($pipelineJob->metadata['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $pipelineJob->source_url,
            'local_path' => $pipelineJob->local_path,
            'content_hash' => $pipelineJob->content_hash,
            'status' => PipelineJob::STATUS_FAILED,
            'metadata' => array_merge($pipelineJob->metadata ?? [], $metadata, [
                'source' => 'MonitorScrapeJob',
                'error_message' => $message,
            ]),
        ]);

        $failedEvent = PipelineEvent::normalize(PipelineEvent::JOB_FAILED, $original);
        app(PipelineEventStateService::class)->upsertJob($failedEvent, PipelineJob::STATUS_FAILED);
        app(PipelineEventBus::class)->publishFailed($original, $exception ?? new RuntimeException($message));
    }

    private function supportedFiles(string $datasetPath): array
    {
        $resolved = realpath($datasetPath);
        if ($resolved === false || !is_dir($resolved)) {
            return [];
        }

        $extensions = array_map('strtolower', config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']));
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
