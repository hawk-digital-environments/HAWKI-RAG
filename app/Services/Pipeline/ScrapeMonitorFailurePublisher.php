<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorFailurePublisher
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineEventStateService $state,
        private PipelineJobRepository $jobs,
        private PipelineEventNormalizer $normalizer,
        private ScrapeMonitorPayloadService $payloads,
    ) {
    }

    public function publish(array $event, string $message, array $metadata = [], ?\Throwable $exception = null): void
    {
        $pipelineJob = $this->jobs->findWithTaskByJobId((string) $event['job_id']);
        if (!$pipelineJob?->task_id) {
            return;
        }

        $original = $this->payloads->failedSourceEvent($pipelineJob, $event, $message, $metadata);
        $failedEvent = $this->normalizer->normalize(PipelineEvent::JOB_FAILED, $original);
        $this->state->upsertJob($failedEvent, PipelineJob::STATUS_FAILED);
        $this->events->publishFailed($original, $exception ?? PipelineEventHandlerException::scrapeMonitorFailure($message));
    }
}
