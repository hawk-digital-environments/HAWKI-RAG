<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class IngestionEventFactory
{
    public function __construct(
        private IngestionContentResolver $content,
        private PipelineEventArtifactReader $artifacts,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function forPath(array $event, string $path): array
    {
        $path = $this->content->resolvePath($path) ?? $path;
        $hash = $this->artifacts->sha256($path);
        $jobId = 'ingest_'.substr(hash('sha256', ($event['task_id'] ?? '').'|'.($event['job_id'] ?? '').'|'.$path), 0, 24);
        $datasetId = (string) ($event['dataset_id'] ?: 'default');

        return PipelineEvent::normalize(PipelineEvent::CONTENT_INGESTED, [
            'task_id' => $event['task_id'],
            'job_id' => $jobId,
            'parent_job_id' => $event['job_id'],
            'dataset_id' => $datasetId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $event['source_url'],
            'local_path' => $path,
            'content_hash' => $hash,
            'status' => PipelineJob::STATUS_RUNNING,
            'metadata' => array_merge($event['metadata'] ?? [], [
                'source_event_type' => $event['event_type'],
                'source_job_id' => $event['job_id'],
            ]),
        ]);
    }
}
