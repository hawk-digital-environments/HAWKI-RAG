<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeEventHandler
{
    public function __construct(
        private ScrapeEventPacketParser $packets,
        private ScrapeEventProcessor $processor,
        private PipelineStageLogger $logger,
    ) {}

    public function handle(array $payload): void
    {
        if (! $this->packets->isValid($payload)) {
            $this->logger->validationFailed('scrape', [
                'job_id' => $payload['job_id'] ?? null,
                'pipeline_stage' => 'event_packet',
                'error_message' => 'Invalid scrape event packet structure.',
                'event_name' => $payload['event'] ?? null,
                'payload_keys' => array_keys($payload),
            ]);

            return;
        }

        $this->processor->process($this->packets->fromArray($payload));
    }
}
