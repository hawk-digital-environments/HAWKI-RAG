<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineWorkerEventRecord;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineWorkerEventRepository
{
    public function findByEventId(string $eventId): ?PipelineWorkerEventRecord
    {
        return PipelineWorkerEventRecord::query()
            ->where('event_id', $eventId)
            ->first();
    }

    public function claim(PipelineWorkerEvent $event, PipelineJob $job): PipelineWorkerEventRecord
    {
        return PipelineWorkerEventRecord::query()->createOrFirst(
            ['event_id' => $event->eventId],
            [
                'pipeline_job_id' => $job->id,
                'job_id' => $event->jobId,
                'task_id' => $event->taskId,
                'source_id' => $event->sourceId,
                'workflow_id' => $event->workflowId,
                'run_id' => $event->runId,
                'activity_id' => $event->activityId,
                'attempt' => $event->attempt,
                'event_type' => $event->eventType,
                'producer' => $event->producer->value,
                'stage' => $event->stage->value,
                'phase' => $event->phase,
                'status' => $event->status->value,
                'payload_hash' => $event->payloadHash,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt,
            ],
        );
    }

    public function markProcessed(PipelineWorkerEventRecord $record, Carbon $processedAt): PipelineWorkerEventRecord
    {
        $record->forceFill(['processed_at' => $processedAt])->save();

        return $record->refresh();
    }
}
