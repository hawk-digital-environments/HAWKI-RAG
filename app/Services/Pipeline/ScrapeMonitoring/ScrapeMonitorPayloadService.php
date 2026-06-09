<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Models\PipelineJob;
use App\Services\Pipeline\EventHandlers\ScrapeMonitorEventHandler;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventNormalizer;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorPayloadService
{
    public function __construct(
        private PipelineEventNormalizer $normalizer,
    ) {}

    public function pageScrapedEvent(PipelineJob $job, string $datasetPath): array
    {
        return $this->normalizer->normalize(PipelineEvent::PAGE_SCRAPED, $this->basePayload($job, $datasetPath));
    }

    public function fileDiscoveredPayload(PipelineJob $job, string $datasetPath, string $path): array
    {
        $task = $job->task;
        $hash = @hash_file('sha256', $path) ?: hash('sha256', $path);

        return [
            'task_id' => $job->task_id,
            'job_id' => 'convert_'.substr(hash('sha256', $job->task_id.'|'.$path), 0, 24),
            'parent_job_id' => $job->job_id,
            'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $job->source_url,
            'local_path' => $path,
            'content_hash' => $hash,
            'status' => PipelineJob::STATUS_PENDING,
            'metadata' => [
                'source' => ScrapeMonitorEventHandler::class,
                'dataset_path' => $datasetPath,
            ],
        ];
    }

    public function failedSourceEvent(PipelineJob $job, array $event, string $message, array $metadata = []): array
    {
        $task = $job->task;

        return $this->normalizer->normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $job->task_id,
            'job_id' => $job->job_id,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? $event['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $job->source_url ?: $event['source_url'],
            'local_path' => $job->local_path ?: $event['local_path'],
            'content_hash' => $job->content_hash ?: $event['content_hash'],
            'status' => PipelineJob::STATUS_FAILED,
            'metadata' => array_merge($job->metadata ?? [], $metadata, [
                'source' => ScrapeMonitorEventHandler::class,
                'error_message' => $message,
            ]),
        ]);
    }

    private function basePayload(PipelineJob $job, string $datasetPath): array
    {
        $task = $job->task;

        return [
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
                'source' => ScrapeMonitorEventHandler::class,
            ]),
        ];
    }
}
