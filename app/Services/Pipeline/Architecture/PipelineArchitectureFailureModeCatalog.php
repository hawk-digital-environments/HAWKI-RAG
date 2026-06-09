<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Architecture;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineArchitectureFailureModeCatalog
{
    public function failureModes(): array
    {
        return [
            [
                'mode' => 'rabbitmq_unavailable',
                'owner' => 'PipelineEventBus and health checks',
                'effect' => 'Publish fails or workers cannot consume.',
                'expectedRecovery' => 'Restore RabbitMQ, declare topology, then retry failed jobs from pipeline recovery.',
            ],
            [
                'mode' => 'worker_missing',
                'owner' => 'pipeline:health and pipeline:workers',
                'effect' => 'Events wait in worker queues and pipeline progress stalls.',
                'expectedRecovery' => 'Start the missing worker for the queue shown in health output.',
            ],
            [
                'mode' => 'external_service_failure',
                'owner' => 'Event handlers',
                'effect' => 'Crawl4AI, converter, Qdrant, Neo4j, or bridge errors mark jobs failed or publish retry events.',
                'expectedRecovery' => 'Fix the dependency and use recovery retry for failed jobs.',
            ],
            [
                'mode' => 'bad_event_payload',
                'owner' => 'PipelineEventDecoder and PipelineEvent::normalize',
                'effect' => 'Malformed messages are rejected before domain handlers run.',
                'expectedRecovery' => 'Fix the producer payload and republish from a valid source event.',
            ],
            [
                'mode' => 'retry_limit_exhausted',
                'owner' => 'PipelineEventRetryFactory and failed event queue',
                'effect' => 'The event is published as job.failed after retries are exhausted.',
                'expectedRecovery' => 'Inspect failed job metadata and retry through the recovery workflow.',
            ],
        ];
    }
}
