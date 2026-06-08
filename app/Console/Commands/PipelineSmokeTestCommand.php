<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\PipelineEventRecord;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class PipelineSmokeTestCommand extends Command
{
    protected $signature = 'pipeline:smoke-test
        {--dataset=smoke-demo : Dataset identifier for the smoke run}
        {--graph=auto : true, false, or auto; auto uses RAG_INGEST_GRAPH}
        {--url= : Optional source URL label for the smoke run}
        {--timeout=15 : HTTP timeout in seconds for Qdrant and Neo4j checks}
        {--keep-files=false : Keep generated fixture files after the run}';

    protected $description = 'Run an end-to-end MVP pipeline smoke test for scrape, convert, ingest, Qdrant, and optional Neo4j.';

    private array $results = [];

    public function handle(
        PipelineTaskService $tasks,
        PipelineEventBus $events,
        PipelineEventStateService $state,
        ConverterEventHandler $converter,
        IngestionEventHandler $ingestion,
        DatasetService $datasets,
    ): int {
        $this->results = [];
        $datasetId = $this->stringOption('dataset') ?: 'smoke-demo';
        $graph = $this->graphOption();
        $timeout = max(1, (int) $this->option('timeout'));
        $keepFiles = $this->booleanOption('keep-files', false);
        $taskId = 'smoke_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $sourceUrl = $this->stringOption('url') ?: "https://example.test/hawki-rag-smoke/{$taskId}";
        $fixtureDir = storage_path("app/pipeline-smoke/{$taskId}");

        $this->line('HAWKI RAG MVP smoke test');
        $this->line("Task ID: {$taskId}");
        $this->line("Dataset: {$datasetId}");
        $this->line('Graph mode: ' . ($graph ? 'true' : 'false'));
        $this->newLine();

        try {
            $fixturePath = $this->stage('Fixture', function () use ($fixtureDir, $taskId): string {
                File::ensureDirectoryExists($fixtureDir);
                $path = $fixtureDir . DIRECTORY_SEPARATOR . 'hawki-smoke.docx';
                $this->writeSmokeDocx($path, $taskId);

                return $path;
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

            $scrapeJob = $this->stage('Scrape enqueue', function () use ($task): PipelineJob {
                $job = PipelineJob::query()
                    ->where('task_id', $task->task_id)
                    ->where('job_type', PipelineJob::TYPE_SCRAPE)
                    ->first();

                if (!$job) {
                    throw new \RuntimeException('No scrape job was created for the smoke task.');
                }

                if (!in_array($job->status, [PipelineJob::STATUS_QUEUED, PipelineJob::STATUS_SKIPPED], true)) {
                    throw new \RuntimeException("Scrape job {$job->job_id} has unexpected status {$job->status}.");
                }

                return $job;
            }, fn (PipelineJob $job): string => "Created scrape job {$job->job_id} with status {$job->status}.");

            $this->stage('RabbitMQ events', function () use ($events, $task, $scrapeJob): string {
                if ((bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
                    foreach (['scraper', 'scrape_monitor', 'converter', 'ingestion'] as $worker) {
                        $events->declareWorkerTopology($worker);
                    }
                }

                $recorded = PipelineEventRecord::query()
                    ->where('task_id', $task->task_id)
                    ->where('job_id', $scrapeJob->job_id)
                    ->where('event_type', PipelineEvent::SCRAPE_REQUESTED)
                    ->exists();

                if (!$recorded) {
                    throw new \RuntimeException('No scrape.requested pipeline event was recorded for the scrape job.');
                }

                return (bool) config('communication.rabbitmq.pipeline_events.enabled', true)
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

            $convertJobId = 'convert_' . substr(hash('sha256', $task->task_id . '|' . $fixturePath), 0, 24);
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

            $convertedPath = $this->stage('Convert', function () use ($converter, $convertJobId, $fileDiscovered): string {
                $converter->handle($fileDiscovered);
                $job = PipelineJob::query()->where('job_id', $convertJobId)->first();
                $path = is_array($job?->metadata) ? (string) ($job->metadata['converted_path'] ?? '') : '';

                if (!$job || $job->status !== PipelineJob::STATUS_COMPLETED) {
                    throw new \RuntimeException("Convert job {$convertJobId} did not complete.");
                }

                if ($path === '' || !is_file($path) || trim((string) file_get_contents($path)) === '') {
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

            $document = $this->stage('Ingest', function () use ($ingestion, $task, $convertedPath, $fileConverted): Document {
                $ingestion->handle($fileConverted);

                $document = Document::query()
                    ->where('dataset_id', $task->dataset_id)
                    ->where('storage_path', realpath($convertedPath) ?: $convertedPath)
                    ->where('status', Document::STATUS_COMPLETED)
                    ->latest('updated_at')
                    ->first();

                if (!$document) {
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

                if (!$document->external_id) {
                    throw new \RuntimeException('Document is missing the related ingestion job id.');
                }

                return $document;
            }, fn (Document $document): string => "Document links back to task {$document->metadata_json['task_id']} and job {$document->external_id}.");

            $dataset = $datasets->ensure($task->dataset_id);
            $this->stage('Qdrant write', function () use ($dataset, $document, $task, $timeout): int {
                return $this->verifyQdrantPoint(
                    (string) $dataset->qdrant_collection,
                    (string) $document->external_id,
                    (string) $task->task_id,
                    $timeout,
                );
            }, fn (int $points): string => "Found {$points} Qdrant point(s) for the smoke document.");

            if ($graph) {
                $this->stage('Neo4j write', function () use ($document, $task, $timeout): array {
                    return $this->verifyNeo4jGraph((string) $document->external_id, (string) $task->task_id, $timeout);
                }, fn (array $counts): string => "Found {$counts['nodes']} node(s) and {$counts['relationships']} relationship(s).");
            } else {
                $this->skip('Neo4j write', 'Graph mode is disabled for this smoke run.');
            }

            $status = $tasks->show($task->task_id);
            $this->line('Dashboard URL: ' . url('/pipeline-dashboard?task_id=' . rawurlencode($task->task_id)));
            $this->line('Documents URL: ' . url('/documents?document_id=' . rawurlencode($document->id)));
            $this->line('Final task status: ' . ($status['status'] ?? 'unknown'));
            $this->newLine();
            $this->printSummary();
            $this->info('Smoke test PASS.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->printSummary();
            $this->line('Smoke test FAIL: ' . $exception->getMessage());

            return self::FAILURE;
        } finally {
            if (!$keepFiles && File::isDirectory($fixtureDir)) {
                File::deleteDirectory($fixtureDir);
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
        $enabled = config('communication.rabbitmq.pipeline_events.enabled', true);
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        try {
            return $callback();
        } finally {
            config()->set('communication.rabbitmq.pipeline_events.enabled', $enabled);
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

    private function writeSmokeDocx(string $path, string $taskId): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is required to create the smoke DOCX fixture.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create DOCX fixture at {$path}.");
        }

        $text = 'HAWKI RAG smoke test document. Laravel orchestrates RabbitMQ jobs. '
            . 'The scraper discovers a document, the converter extracts Markdown, and ingestion writes Qdrant points. '
            . "Smoke task {$taskId}.";
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
        $zip->addFromString('word/document.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>{$escaped}</w:t></w:r></w:p>
  </w:body>
</w:document>
XML);
        $zip->close();
    }

    private function verifyQdrantPoint(string $collection, string $jobId, string $taskId, int $timeout): int
    {
        $url = rtrim((string) config('config.qdrant_http_url'), '/');
        if ($url === '') {
            $qdrant = config('model_providers.vector_stores.qdrant', []);
            $url = sprintf('%s://%s:%s', $qdrant['scheme'] ?? 'http', $qdrant['host'] ?? 'qdrant', $qdrant['port'] ?? 6333);
        }

        foreach ([
            ['job_id', $jobId],
            ['doc_id', $jobId],
            ['task_id', $taskId],
        ] as [$key, $value]) {
            $request = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()->asJson();
            if ($apiKey = config('model_providers.vector_stores.qdrant.api_key')) {
                $request = $request->withHeader('api-key', (string) $apiKey);
            }

            $response = $request->post($url . '/collections/' . rawurlencode($collection) . '/points/scroll', [
                'limit' => 3,
                'with_payload' => true,
                'with_vector' => false,
                'filter' => [
                    'must' => [[
                        'key' => $key,
                        'match' => ['value' => $value],
                    ]],
                ],
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Qdrant returned HTTP {$response->status()} for collection {$collection}.");
            }

            $points = $response->json('result.points') ?? [];
            if (is_array($points) && count($points) > 0) {
                return count($points);
            }
        }

        throw new \RuntimeException("No Qdrant point found for job {$jobId} or task {$taskId} in collection {$collection}.");
    }

    private function verifyNeo4jGraph(string $documentJobId, string $taskId, int $timeout): array
    {
        $url = rtrim((string) config('config.neo4j_http_url'), '/');
        $database = trim((string) env('NEO4J_DATABASE', 'neo4j')) ?: 'neo4j';
        $response = Http::timeout($timeout)
            ->connectTimeout($timeout)
            ->withBasicAuth((string) config('config.neo4j_user'), (string) config('config.neo4j_password'))
            ->acceptJson()
            ->asJson()
            ->post($url . '/db/' . rawurlencode($database) . '/tx/commit', [
                'statements' => [[
                    'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.doc_id = $doc_id
   OR n.job_id = $doc_id
   OR n.task_id = $task_id
   OR $doc_id IN coalesce(n.doc_ids, [])
WITH count(n) AS nodes
OPTIONAL MATCH ()-[r]->()
WHERE r.doc_id = $doc_id
   OR r.job_id = $doc_id
   OR r.task_id = $task_id
   OR $doc_id IN coalesce(r.doc_ids, [])
RETURN nodes, count(r) AS relationships
CYPHER,
                    'parameters' => [
                        'doc_id' => $documentJobId,
                        'task_id' => $taskId,
                    ],
                ]],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Neo4j returned HTTP {$response->status()}.");
        }

        $errors = $response->json('errors') ?? [];
        if ($errors !== []) {
            throw new \RuntimeException('Neo4j returned errors: ' . json_encode($errors, JSON_UNESCAPED_SLASHES));
        }

        $row = $response->json('results.0.data.0.row') ?? [0, 0];
        $nodes = (int) ($row[0] ?? 0);
        $relationships = (int) ($row[1] ?? 0);
        if ($nodes < 1 && $relationships < 1) {
            throw new \RuntimeException("No Neo4j graph records found for smoke document {$documentJobId}.");
        }

        return [
            'nodes' => $nodes,
            'relationships' => $relationships,
        ];
    }

    private function graphOption(): bool
    {
        $value = $this->stringOption('graph');
        if ($value === null || strtolower($value) === 'auto') {
            return filter_var(config('communication.rabbitmq.pipeline_ingestion.graph'), FILTER_VALIDATE_BOOLEAN);
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_bool($parsed)) {
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
}
