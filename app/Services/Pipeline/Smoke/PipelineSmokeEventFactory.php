<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\PipelineFileHasher;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineSmokeEventFactory
{
    public function __construct(private PipelineFileHasher $hasher)
    {
    }

    public function pageScraped(
        PipelineTask $task,
        PipelineJob $scrapeJob,
        string $sourceUrl,
        string $fixturePath,
        bool $graph,
    ): array {
        $contentHash = $this->hasher->sha256($fixturePath);

        return PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => $task->task_id,
            'job_id' => $scrapeJob->job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $sourceUrl,
            'local_path' => $fixturePath,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($scrapeJob->metadata ?? [], [
                'source' => 'pipeline-smoke-test',
                'graph' => $graph,
                'rag_ingest_graph' => $graph,
                'fixture_path' => $fixturePath,
            ]),
        ]);
    }

    public function convertJobId(string $taskId, string $fixturePath): string
    {
        return 'convert_'.substr(hash('sha256', $taskId.'|'.$fixturePath), 0, 24);
    }

    public function fileDiscovered(
        PipelineTask $task,
        PipelineJob $scrapeJob,
        string $convertJobId,
        string $sourceUrl,
        string $fixturePath,
        bool $graph,
    ): array {
        $contentHash = $this->hasher->sha256($fixturePath);

        return PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => $task->task_id,
            'job_id' => $convertJobId,
            'parent_job_id' => $scrapeJob->job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $fixturePath,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => [
                'source' => 'pipeline-smoke-test',
                'source_event_type' => PipelineEvent::PAGE_SCRAPED,
                'source_job_id' => $scrapeJob->job_id,
                'original_path' => $fixturePath,
                'graph' => $graph,
                'rag_ingest_graph' => $graph,
            ],
        ]);
    }

    public function fileConverted(
        PipelineTask $task,
        PipelineJob $scrapeJob,
        string $convertJobId,
        string $sourceUrl,
        string $fixturePath,
        string $convertedPath,
        bool $graph,
    ): array {
        $convertedHash = $this->hasher->sha256($convertedPath);

        return PipelineEvent::normalize(PipelineEvent::FILE_CONVERTED, [
            'task_id' => $task->task_id,
            'job_id' => $convertJobId,
            'parent_job_id' => $scrapeJob->job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $convertedPath,
            'content_hash' => $convertedHash,
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => [
                'source' => 'pipeline-smoke-test',
                'source_event_type' => PipelineEvent::FILE_DISCOVERED,
                'source_job_id' => $convertJobId,
                'original_path' => $fixturePath,
                'converted_path' => $convertedPath,
                'graph' => $graph,
                'rag_ingest_graph' => $graph,
            ],
        ]);
    }
}
