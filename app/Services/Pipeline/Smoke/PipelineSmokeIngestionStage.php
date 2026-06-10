<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\DocumentRepository;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Exceptions\PipelineSmokeException;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineSmokeIngestionStage
{
    public function __construct(private PipelineSmokeEventFactory $eventFactory)
    {
    }

    public function run(
        PipelineSmokeStageRunner $runner,
        PipelineSmokeRunContext $context,
        PipelineTask $task,
        PipelineJob $scrapeJob,
        string $fixturePath,
        PipelineSmokeConversionResult $conversion,
        IngestionEventHandler $ingestion,
        DocumentRepository $documents,
    ): Document {
        $fileConverted = $this->eventFactory->fileConverted(
            $task,
            $scrapeJob,
            $conversion->convertJobId,
            $context->sourceUrl,
            $fixturePath,
            $conversion->convertedPath,
            $context->graph,
        );

        $document = $runner->stage('Ingest', function () use ($documents, $ingestion, $task, $conversion, $fileConverted): Document {
            $ingestion->handle($fileConverted);

            $document = $documents->latestCompletedForDatasetPath((string) $task->dataset_id, $conversion->convertedPath);
            if (! $document) {
                throw PipelineSmokeException::ingestionMissingDocument();
            }

            return $document;
        }, fn (Document $document): string => "Created document {$document->id} for ingest job {$document->external_id}.");

        return $runner->stage('Document record', function () use ($document): Document {
            $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
            $bridge = is_array($metadata['bridge_response'] ?? null) ? $metadata['bridge_response'] : [];

            if (($bridge['ok'] ?? true) !== true) {
                throw PipelineSmokeException::bridgeResponseNotOk();
            }

            if (! $document->external_id) {
                throw PipelineSmokeException::documentMissingIngestJob();
            }

            return $document;
        }, fn (Document $document): string => "Document links back to task {$document->metadata_json['task_id']} and job {$document->external_id}.");
    }
}
