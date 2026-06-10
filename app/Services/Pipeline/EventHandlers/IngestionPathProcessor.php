<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class IngestionPathProcessor
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineEventStateService $state,
        private PipelineEventArtifactReader $artifacts,
        private IngestionBridgeClient $bridge,
        private IngestionEventFactory $eventsForIngestion,
        private IngestionProcessingStateWriter $processingStates,
        private IngestedDocumentRecorder $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $sourceEvent
     */
    public function ingest(array $sourceEvent, string $path): void
    {
        $event = $this->eventsForIngestion->forPath($sourceEvent, $path);
        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source_event_type' => $sourceEvent['event_type'],
        ]);
        $this->processingStates->mark($event, JobProcessingState::STATUS_PROCESSING);

        $text = $this->artifacts->readText($path);
        if (trim($text) === '') {
            throw PipelineEventHandlerException::ingestContentIsEmpty($path);
        }

        $bridgeResponse = $this->bridge->ingest($event, $text, $path);

        $this->state->upsertJob($event, PipelineJob::STATUS_COMPLETED, [
            'bridge_response' => $bridgeResponse,
        ]);
        $this->processingStates->mark($event, JobProcessingState::STATUS_COMPLETED);
        $this->documents->record($event, $path, $bridgeResponse);

        $this->events->publish(PipelineEvent::CONTENT_INGESTED, array_merge($event, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($event['metadata'], [
                'bridge_response' => $bridgeResponse,
            ]),
        ]));
    }
}
