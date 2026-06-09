<?php

namespace App\Services\Pipeline\Smoke;

use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Documents\DocumentRepository;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Throwable;

class PipelineSmokeTestWorkflow
{
    private ConsoleWorkflowIO $io;

    private array $results = [];

    public function __construct(
        private readonly PipelineSmokeFixtureFactory $fixtures,
        private readonly PipelineSmokeExternalVerifier $externalVerifier,
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock,
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
        PipelineJobRepository $jobs,
        PipelineEventRecordRepository $eventRecords,
    ): int {
        $this->io = $io;
        $this->results = [];
        $datasetId = $this->stringOption('dataset') ?: 'smoke-demo';
        $graph = $this->graphOption();
        $timeout = max(1, (int) $this->option('timeout'));
        $keepFiles = $this->booleanOption('keep-files', false);
        $taskId = 'smoke_'.$this->clock->now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
        $sourceUrl = $this->stringOption('url') ?: "https://example.test/hawki-rag-smoke/{$taskId}";
        $fixtureDir = storage_path("app/pipeline-smoke/{$taskId}");

        $this->line('HAWKI RAG MVP smoke test');
        $this->line("Task ID: {$taskId}");
        $this->line("Dataset: {$datasetId}");
        $this->line('Graph mode: '.($graph ? 'true' : 'false'));
        $this->newLine();

        try {
            $fixturePath = $this->stage('Fixture', function () use ($fixtureDir, $taskId): string {
                return $this->fixtures->createDocx($fixtureDir, $taskId);
            }, fn (string $path): string => "Created DOCX fixture at {$path}.");

            $task = $this->stage('Task', function () use ($tasks, $taskId, $datasetId, $sourceUrl, $graph): PipelineTask {
                return $this->withoutRabbitMqPublishing(fn (): PipelineTask => $tasks->start([
                    'task_id' => $taskId,
                    'dataset_id' => $datasetId,
                    'urls' => [$sourceUrl],
                    'metadata' => [
                        'source' => 'pipeline-smoke-test',
                        'label' => 'pipeline-smoke',
                        'catalog_task_label' => 'Pipeline smoke test',
                        'max_pages' => 1,
                        'max_concurrency' => 1,
                        'max_rpm' => 30,
                        'skip_images' => true,
                        'discovery_mode' => false,
                        'graph' => $graph,
                        'rag_ingest_graph' => $graph,
                    ],
                ]));
            }, fn (PipelineTask $task): string => "Created task {$task->task_id}.");

            $scrapeJob = $this->stage('Scrape enqueue', function () use ($jobs, $task): PipelineJob {
                $job = $jobs->firstForTaskAndType($task->task_id, PipelineJob::TYPE_SCRAPE);
                if (! $job) {
                    throw new \RuntimeException('No scrape job was created for the smoke task.');
                }

                if (! in_array($job->status, [PipelineJob::STATUS_QUEUED, PipelineJob::STATUS_SKIPPED], true)) {
                    throw new \RuntimeException("Scrape job {$job->job_id} has unexpected status {$job->status}.");
                }

                return $job;
            }, fn (PipelineJob $job): string => "Created scrape job {$job->job_id} with status {$job->status}.");

            $this->stage('RabbitMQ events', function () use ($eventRecords, $events, $task, $scrapeJob): string {
                if ((bool) $this->config->get('communication.rabbitmq.pipeline_events.enabled', true)) {
                    foreach (['scraper', 'scrape_monitor', 'converter', 'ingestion'] as $worker) {
                        $events->declareWorkerTopology($worker);
                    }
                }

                $recorded = $eventRecords->existsForJobEvent($task->task_id, $scrapeJob->job_id, PipelineEvent::SCRAPE_REQUESTED);

                if (! $recorded) {
                    throw new \RuntimeException('No scrape.requested pipeline event was recorded for the scrape job.');
                }

                return (bool) $this->config->get('communication.rabbitmq.pipeline_events.enabled', true)
                    ? 'RabbitMQ topology is reachable and scrape.requested was recorded without sending the synthetic smoke URL to live workers.'
                    : 'RabbitMQ publishing is disabled; Laravel event recording was verified.';
            }, fn (string $message): string => $message);

            $contentHash = hash_file('sha256', $fixturePath) ?: hash('sha256', $fixturePath);
            $pageEvent = PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
                'task_id' => $task->task_id,
                'job_id' => $scrapeJob->job_id,
                'dataset_id' => $task->dataset_id,
                'job_type' => PipelineJob::TYPE_SCRAPE,
                'source_url' => $sourceUrl,
                'local_path' => $fixturePath,
                'content_hash' => $contentHash,
                'status' => PipelineJob::STATUS_COMPLETED,
                'metadata' => array_merge($scrapeJob->metadata ?? [], [
                    'source' => 'pipeline-smoke-test',
                    'graph' => $graph,
                    'rag_ingest_graph' => $graph,
                    'fixture_path' => $fixturePath,
                ]),
            ]);

            $this->stage('Scrape artifact', function () use ($state, $pageEvent): PipelineJob {
                return $state->upsertJob($pageEvent, PipelineJob::STATUS_COMPLETED, [
                    'stage' => 'smoke_scrape_artifact_ready',
                    'reason' => 'Smoke test generated a local scrape artifact.',
                ]);
            }, fn (PipelineJob $job): string => "Marked scrape job {$job->job_id} completed with local artifact.");

            $convertJobId = 'convert_'.substr(hash('sha256', $task->task_id.'|'.$fixturePath), 0, 24);
            $fileDiscovered = PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
                'task_id' => $task->task_id,
                'job_id' => $convertJobId,
                'parent_job_id' => $scrapeJob->job_id,
                'dataset_id' => $task->dataset_id,
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => $sourceUrl,
                'local_path' => $fixturePath,
                'content_hash' => $contentHash,
                'status' => PipelineJob::STATUS_QUEUED,
                'metadata' => [
                    'source' => 'pipeline-smoke-test',
                    'source_event_type' => PipelineEvent::PAGE_SCRAPED,
                    'source_job_id' => $scrapeJob->job_id,
                    'original_path' => $fixturePath,
                    'graph' => $graph,
                    'rag_ingest_graph' => $graph,
                ],
            ]);

            $convertedPath = $this->stage('Convert', function () use ($converter, $convertJobId, $fileDiscovered, $jobs): string {
                $converter->handle($fileDiscovered);
                $job = $jobs->findByJobId($convertJobId);
                $path = is_array($job?->metadata) ? (string) ($job->metadata['converted_path'] ?? '') : '';

                if (! $job || $job->status !== PipelineJob::STATUS_COMPLETED) {
                    throw new \RuntimeException("Convert job {$convertJobId} did not complete.");
                }

                if ($path === '' || ! is_file($path) || trim((string) file_get_contents($path)) === '') {
                    throw new \RuntimeException("Convert job {$convertJobId} did not produce readable Markdown.");
                }

                return $path;
            }, fn (string $path): string => "Converted fixture to Markdown at {$path}.");

            $convertedHash = hash_file('sha256', $convertedPath) ?: hash('sha256', $convertedPath);
            $fileConverted = PipelineEvent::normalize(PipelineEvent::FILE_CONVERTED, [
                'task_id' => $task->task_id,
                'job_id' => $convertJobId,
                'parent_job_id' => $scrapeJob->job_id,
                'dataset_id' => $task->dataset_id,
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => $sourceUrl,
                'local_path' => $convertedPath,
                'content_hash' => $convertedHash,
                'status' => PipelineJob::STATUS_COMPLETED,
                'metadata' => [
                    'source' => 'pipeline-smoke-test',
                    'source_event_type' => PipelineEvent::FILE_DISCOVERED,
                    'source_job_id' => $convertJobId,
                    'original_path' => $fixturePath,
                    'converted_path' => $convertedPath,
                    'graph' => $graph,
                    'rag_ingest_graph' => $graph,
                ],
            ]);

            $document = $this->stage('Ingest', function () use ($documents, $ingestion, $task, $convertedPath, $fileConverted): Document {
                $ingestion->handle($fileConverted);

                $document = $documents->latestCompletedForDatasetPath((string) $task->dataset_id, $convertedPath);
                if (! $document) {
                    throw new \RuntimeException('Ingestion completed without creating a completed document record.');
                }

                return $document;
            }, fn (Document $document): string => "Created document {$document->id} for ingest job {$document->external_id}.");

            $this->stage('Document record', function () use ($document): Document {
                $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
                $bridge = is_array($metadata['bridge_response'] ?? null) ? $metadata['bridge_response'] : [];

                if (($bridge['ok'] ?? true) !== true) {
                    throw new \RuntimeException('Document bridge_response is not ok.');
                }

                if (! $document->external_id) {
                    throw new \RuntimeException('Document is missing the related ingestion job id.');
                }

                return $document;
            }, fn (Document $document): string => "Document links back to task {$document->metadata_json['task_id']} and job {$document->external_id}.");

            $dataset = $datasets->ensure($task->dataset_id);
            $this->stage('Qdrant write', function () use ($dataset, $document, $task, $timeout): int {
                return $this->externalVerifier->verifyQdrantPoint(
                    (string) $dataset->qdrant_collection,
                    (string) $document->external_id,
                    (string) $task->task_id,
                    $timeout,
                );
            }, fn (int $points): string => "Found {$points} Qdrant point(s) for the smoke document.");

            if ($graph) {
                $this->stage('Neo4j write', function () use ($document, $task, $timeout): array {
                    return $this->externalVerifier->verifyNeo4jGraph((string) $document->external_id, (string) $task->task_id, $timeout);
                }, fn (array $counts): string => "Found {$counts['nodes']} node(s) and {$counts['relationships']} relationship(s).");
            } else {
                $this->skip('Neo4j write', 'Graph mode is disabled for this smoke run.');
            }

            $status = $tasks->show($task->task_id);
            $this->line('Dashboard URL: '.url('/pipeline-dashboard?task_id='.rawurlencode($task->task_id)));
            $this->line('Documents URL: '.url('/documents?document_id='.rawurlencode($document->id)));
            $this->line('Final task status: '.($status['status'] ?? 'unknown'));
            $this->newLine();
            $this->printSummary();
            $this->info('Smoke test PASS.');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->printSummary();
            $this->line('Smoke test FAIL: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            if (! $keepFiles && $this->files->isDirectory($fixtureDir)) {
                $this->files->deleteDirectory($fixtureDir);
            }
        }
    }

    private function stage(string $name, callable $callback, callable $message): mixed
    {
        try {
            $value = $callback();
            $text = $message($value);
            $this->recordPass($name, $text);

            return $value;
        } catch (Throwable $exception) {
            $this->recordFail($name, $exception->getMessage());
            throw $exception;
        }
    }

    private function withoutRabbitMqPublishing(callable $callback): mixed
    {
        $enabled = $this->config->get('communication.rabbitmq.pipeline_events.enabled', true);
        $this->config->set('communication.rabbitmq.pipeline_events.enabled', false);

        try {
            return $callback();
        } finally {
            $this->config->set('communication.rabbitmq.pipeline_events.enabled', $enabled);
        }
    }

    private function recordPass(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'PASS', 'stage' => $stage, 'message' => $message];
        $this->info("PASS {$stage}: {$message}");
    }

    private function recordFail(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'FAIL', 'stage' => $stage, 'message' => $message];
        $this->error("FAIL {$stage}: {$message}");
    }

    private function skip(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'SKIP', 'stage' => $stage, 'message' => $message];
        $this->warn("SKIP {$stage}: {$message}");
    }

    private function printSummary(): void
    {
        $this->line('Smoke summary');
        foreach ($this->results as $result) {
            $this->line(sprintf(
                '  [%s] %s - %s',
                $result['status'],
                $result['stage'],
                $result['message'],
            ));
        }
        $this->newLine();
    }

    private function graphOption(): bool
    {
        $value = $this->stringOption('graph');
        if ($value === null || strtolower($value) === 'auto') {
            return $this->externalVerifier->defaultGraphEnabled();
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (! is_bool($parsed)) {
            throw new \InvalidArgumentException('The --graph option must be true, false, or auto.');
        }

        return $parsed;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function booleanOption(string $name, bool $default): bool
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : $default;
    }

    private function option(string $name): mixed
    {
        return $this->io->option($name);
    }

    private function line(string $message): void
    {
        $this->io->line($message);
    }

    private function info(string $message): void
    {
        $this->io->info($message);
    }

    private function error(string $message): void
    {
        $this->io->error($message);
    }

    private function warn(string $message): void
    {
        $this->io->warn($message);
    }

    private function newLine(): void
    {
        $this->io->newLine();
    }
}
