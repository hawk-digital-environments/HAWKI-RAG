<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentPipelineSummaryService
{
    public function __construct(
        private ManagedDocumentRepository $documents,
        private ManagedDocumentSyncService $sync,
        private ManagedDocumentPayloadBuilder $payloads,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForTask(PipelineTask $task): array
    {
        if (! $task->exists || ! is_string($task->task_id) || trim($task->task_id) === '') {
            return [];
        }

        return $this->summaries($this->documents->forLatestTaskId($task->task_id)->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForJob(PipelineJob $job): array
    {
        if (! $job->exists || ! is_string($job->job_id) || trim($job->job_id) === '') {
            return [];
        }

        return $this->summaries($this->documents->forLatestJobId($job->job_id)->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForSourceId(?string $sourceId): array
    {
        $sourceId = is_string($sourceId) ? trim($sourceId) : '';
        if ($sourceId === '') {
            return [];
        }

        return $this->summaries($this->documents->forLatestSourceId($sourceId)->all());
    }

    /**
     * @param list<ManagedDocument> $documents
     * @return list<array<string, mixed>>
     */
    private function summaries(array $documents): array
    {
        return collect($documents)
            ->map(function (ManagedDocument $document): array {
                return $this->payloads->build($this->sync->sync($document), includeDetails: false);
            })
            ->values()
            ->all();
    }
}
