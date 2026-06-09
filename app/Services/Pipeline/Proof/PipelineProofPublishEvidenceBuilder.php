<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineProofPublishEvidenceBuilder
{
    public function __construct(
        private PipelineProofValueResolver $values,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(array $finalStatus, array $databaseState): array
    {
        $ingestStage = $this->values->stageFromStatus($finalStatus, 'ingest');
        $ingestRow = $this->values->stageRow($databaseState, 'ingest');
        $metadata = is_array($ingestStage['metadata'] ?? null)
            ? $ingestStage['metadata']
            : (is_array($ingestRow['metadata'] ?? null) ? $ingestRow['metadata'] : []);

        return [
            'publisher' => $metadata['publisher'] ?? null,
            'folder' => $metadata['folder'] ?? ($ingestRow['metadata']['folder'] ?? null),
            'documentsPublished' => $ingestStage['counts']['total'] ?? $ingestRow['counts']['total'] ?? null,
            'routingKey' => $this->config->get('communication.rabbitmq.pipeline_events.events.content_ingested', 'content.ingested'),
            'eventsExchange' => $this->config->get('communication.rabbitmq.pipeline_events.exchange', 'pipeline.events'),
            'exitCode' => $metadata['exitCode'] ?? null,
            'status' => $ingestStage['status'] ?? $ingestRow['status'] ?? 'unknown',
        ];
    }
}
