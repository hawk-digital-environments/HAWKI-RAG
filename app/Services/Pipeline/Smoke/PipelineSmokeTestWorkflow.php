<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Document\DocumentRepository;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Exceptions\PipelineSmokeException;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Throwable;

#[Singleton]
readonly class PipelineSmokeTestWorkflow
{
    public function __construct(
        private PipelineSmokeFixtureFactory $fixtures,
        private PipelineSmokeExternalVerifier $externalVerifier,
        private PipelineSmokeRunContextFactory $runContexts,
        private PipelineSmokeRabbitMqPublishingGate $publishingGate,
        private PipelineSmokeEventFactory $eventFactory,
        private PipelineSmokeResultReporter $results,
        private Filesystem $files,
    ) {
    }

    public function run(
        ConsoleWorkflowIO $io,
        PipelineTaskService $tasks,
        PipelineEventBus $events,
        PipelineEventStateService $state,
        ConverterEventHandler $converter,
        IngestionEventHandler $ingestion,
        DatasetService $datasets,
        DocumentRepository $documents,
        ActivePipelineJobsQuery $jobs,
        PipelineEventRecordRepository $eventRecords,
    ): int {
        $runner = new PipelineSmokeStageRunner($io);
        $context = $this->runContexts->fromIO($io);

        $io->line('HAWKI RAG MVP smoke test');
        $io->line("Task ID: {$context->taskId}");
        $io->line("Dataset: {$context->datasetId}");
        $io->line('Graph mode: '.($context->graph ? 'true' : 'false'));
        $io->newLine();

        try {
            $fixturePath = $runner->stage('Fixture', function () use ($context): string {
                return $this->fixtures->createDocx($context->fixtureDir, $context->taskId);
            }, fn (string $path): string => "Created DOCX fixture at {$path}.");

            $task = $runner->stage('Task', function () use ($tasks, $context): PipelineTask {
                return $this->publishingGate->withoutPublishing(fn (): PipelineTask => $tasks->start([
                    'task_id' => $context->taskId,
                    'dataset_id' => $context->datasetId,
                    'urls' => [$context->sourceUrl],
                    'metadata' => [
                        'source' => 'pipeline-smoke-test',
                        'label' => 'pipeline-smoke',
                        'catalog_task_label' => 'Pipeline smoke test',
                        'max_pages' => 1,
                        'max_concurrency' => 1,
                        'max_rpm' => 30,
                        'skip_images' => true,
                        'discovery_mode' => false,
                        'graph' => $context->graph,
                        'rag_ingest_graph' => $context->graph,
                    ],
                ]));
            }, fn (PipelineTask $task): string => "Created task {$task->task_id}.");

            $scrapeJob = $runner->stage('Scrape enqueue', function () use ($jobs, $task): PipelineJob {
                $job = $jobs->firstForTaskAndType($task->task_id, PipelineJob::TYPE_SCRAPE);
                if (! $job) {
                    throw PipelineSmokeException::missingScrapeJob();
                }

                if (! in_array($job->status, [PipelineJob::STATUS_QUEUED, PipelineJob::STATUS_SKIPPED], true)) {
                    throw PipelineSmokeException::unexpectedScrapeJobStatus($job->job_id, $job->status);
                }

                return $job;
            }, fn (PipelineJob $job): string => "Created scrape job {$job->job_id} with status {$job->status}.");

            $runner->stage('RabbitMQ events', function () use ($eventRecords, $events, $task, $scrapeJob): string {
                if ($this->publishingGate->enabled()) {
                    foreach (['scraper', 'scrape_monitor', 'converter', 'ingestion'] as $worker) {
                        $events->declareWorkerTopology($worker);
                    }
                }

                $recorded = $eventRecords->existsForJobEvent($task->task_id, $scrapeJob->job_id, PipelineEvent::SCRAPE_REQUESTED);

                if (! $recorded) {
                    throw PipelineSmokeException::missingScrapeRequestedEvent();
                }

                return $this->publishingGate->enabled()
                    ? 'RabbitMQ topology is reachable and scrape.requested was recorded without sending the synthetic smoke URL to live workers.'
                    : 'RabbitMQ publishing is disabled; Laravel event recording was verified.';
            }, fn (string $message): string => $message);

            $pageEvent = $this->eventFactory->pageScraped($task, $scrapeJob, $context->sourceUrl, $fixturePath, $context->graph);
            $runner->stage('Scrape artifact', function () use ($state, $pageEvent): PipelineJob {
                return $state->upsertJob($pageEvent, PipelineJob::STATUS_COMPLETED, [
                    'stage' => 'smoke_scrape_artifact_ready',
                    'reason' => 'Smoke test generated a local scrape artifact.',
                ]);
            }, fn (PipelineJob $job): string => "Marked scrape job {$job->job_id} completed with local artifact.");

            $convertJobId = $this->eventFactory->convertJobId($task->task_id, $fixturePath);
            $fileDiscovered = $this->eventFactory->fileDiscovered(
                $task,
                $scrapeJob,
                $convertJobId,
                $context->sourceUrl,
                $fixturePath,
                $context->graph,
            );

            $convertedPath = $runner->stage('Convert', function () use ($converter, $convertJobId, $fileDiscovered, $jobs): string {
                $converter->handle($fileDiscovered);
                $job = $jobs->findByJobId($convertJobId);
                $path = is_array($job?->metadata) ? (string) ($job->metadata['converted_path'] ?? '') : '';

                if (! $job || $job->status !== PipelineJob::STATUS_COMPLETED) {
                    throw PipelineSmokeException::convertDidNotComplete($convertJobId);
                }

                if ($path === '' || ! $this->files->isFile($path) || trim($this->files->get($path)) === '') {
                    throw PipelineSmokeException::convertMarkdownMissing($convertJobId);
                }

                return $path;
            }, fn (string $path): string => "Converted fixture to Markdown at {$path}.");

            $fileConverted = $this->eventFactory->fileConverted(
                $task,
                $scrapeJob,
                $convertJobId,
                $context->sourceUrl,
                $fixturePath,
                $convertedPath,
                $context->graph,
            );

            $document = $runner->stage('Ingest', function () use ($documents, $ingestion, $task, $convertedPath, $fileConverted): Document {
                $ingestion->handle($fileConverted);

                $document = $documents->latestCompletedForDatasetPath((string) $task->dataset_id, $convertedPath);
                if (! $document) {
                    throw PipelineSmokeException::ingestionMissingDocument();
                }

                return $document;
            }, fn (Document $document): string => "Created document {$document->id} for ingest job {$document->external_id}.");

            $runner->stage('Document record', function () use ($document): Document {
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

            $dataset = $datasets->ensure($task->dataset_id);
            $runner->stage('Qdrant write', function () use ($dataset, $document, $task, $context): int {
                return $this->externalVerifier->verifyQdrantPoint(
                    (string) $dataset->qdrant_collection,
                    (string) $document->external_id,
                    (string) $task->task_id,
                    $context->timeout,
                );
            }, fn (int $points): string => "Found {$points} Qdrant point(s) for the smoke document.");

            if ($context->graph) {
                $runner->stage('Neo4j write', function () use ($document, $task, $context): array {
                    return $this->externalVerifier->verifyNeo4jGraph((string) $document->external_id, (string) $task->task_id, $context->timeout);
                }, fn (array $counts): string => "Found {$counts['nodes']} node(s) and {$counts['relationships']} relationship(s).");
            } else {
                $runner->skip('Neo4j write', 'Graph mode is disabled for this smoke run.');
            }

            $status = $tasks->show($task->task_id);
            $this->results->printSuccess($io, $runner, $task, $document, $status);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->newLine();
            $runner->printSummary();
            $io->line('Smoke test FAIL: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            if (! $context->keepFiles && $this->files->isDirectory($context->fixtureDir)) {
                $this->files->deleteDirectory($context->fixtureDir);
            }
        }
    }
}
