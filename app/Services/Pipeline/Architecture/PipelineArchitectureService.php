<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Architecture;

use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\Pipeline\Events\PipelineEventTypeRegistry;
use App\Services\Pipeline\Queues\PipelineQueueTopologyService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineArchitectureService
{
    public function __construct(
        private PipelineQueueTopologyService $queues,
        private PipelineEventTypeRegistry $types,
        private PipelineEventConfig $config,
    ) {}

    public function summary(): array
    {
        return [
            'events' => $this->events(),
            'flow' => $this->flow(),
            'topology' => $this->topology(),
            'handlers' => $this->handlers(),
            'persistence' => $this->persistence(),
            'idempotency' => $this->idempotency(),
            'recovery' => $this->recovery(),
            'health' => $this->health(),
            'testing' => $this->testing(),
            'failureModes' => $this->failureModes(),
            'mentalModel' => $this->mentalModel(),
        ];
    }

    public function events(): array
    {
        return array_map(fn (array $event): array => array_merge($event, [
            'requiredFields' => PipelineEvent::REQUIRED_PAYLOAD_FIELDS,
            'consumedBy' => $this->consumersFor($event['eventType']),
        ]), [
            [
                'eventType' => PipelineEvent::SCRAPE_REQUESTED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::SCRAPE_REQUESTED),
                'purpose' => 'Request Crawl4AI to scrape a source URL.',
                'typicalProducer' => 'PipelineTaskService or pipeline API',
                'typicalNextEvents' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'eventType' => PipelineEvent::SCRAPE_MONITOR_REQUESTED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::SCRAPE_MONITOR_REQUESTED),
                'purpose' => 'Check Crawl4AI status once and reschedule if the crawl is still running.',
                'typicalProducer' => 'ScraperEventHandler or ScrapeMonitorEventHandler',
                'typicalNextEvents' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_DISCOVERED, PipelineEvent::JOB_FAILED],
            ],
            [
                'eventType' => PipelineEvent::PAGE_SCRAPED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::PAGE_SCRAPED),
                'purpose' => 'Ingest scraped page markdown/content.',
                'typicalProducer' => 'ScrapeMonitorEventHandler',
                'typicalNextEvents' => [PipelineEvent::CONTENT_INGESTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'eventType' => PipelineEvent::FILE_DISCOVERED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::FILE_DISCOVERED),
                'purpose' => 'Convert a discovered supported file such as PDF or DOCX.',
                'typicalProducer' => 'ScrapeMonitorEventHandler or upload pipeline',
                'typicalNextEvents' => [PipelineEvent::FILE_CONVERTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'eventType' => PipelineEvent::FILE_CONVERTED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::FILE_CONVERTED),
                'purpose' => 'Ingest converted markdown from a file conversion.',
                'typicalProducer' => 'ConverterEventHandler',
                'typicalNextEvents' => [PipelineEvent::CONTENT_INGESTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'eventType' => PipelineEvent::CONTENT_INGESTED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::CONTENT_INGESTED),
                'purpose' => 'Record that content reached the RAG ingestion bridge.',
                'typicalProducer' => 'IngestionEventHandler',
                'typicalNextEvents' => [],
            ],
            [
                'eventType' => PipelineEvent::JOB_FAILED,
                'jobType' => $this->types->jobTypeFor(PipelineEvent::JOB_FAILED),
                'purpose' => 'Record an exhausted or unrecoverable pipeline failure.',
                'typicalProducer' => 'PipelineEventBus or worker failure path',
                'typicalNextEvents' => [],
            ],
        ]);
    }

    public function flow(): array
    {
        return [
            [PipelineEvent::SCRAPE_REQUESTED, PipelineEvent::SCRAPE_MONITOR_REQUESTED],
            [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::SCRAPE_MONITOR_REQUESTED],
            [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::PAGE_SCRAPED],
            [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::FILE_DISCOVERED],
            [PipelineEvent::FILE_DISCOVERED, PipelineEvent::FILE_CONVERTED],
            [PipelineEvent::PAGE_SCRAPED, PipelineEvent::CONTENT_INGESTED],
            [PipelineEvent::FILE_CONVERTED, PipelineEvent::CONTENT_INGESTED],
        ];
    }

    public function topology(): array
    {
        return [
            'eventsExchange' => $this->config->exchange(),
            'retryExchange' => $this->config->retryExchange(),
            'failedExchange' => $this->config->failedExchange(),
            'retryDelayMs' => $this->config->retryDelayMs(),
            'maxRetries' => $this->config->maxRetries(),
            'queueType' => $this->config->queueType(),
            'failedRoutingKey' => $this->config->failedRoutingKey(),
            'queues' => $this->queues->expectedQueues(),
        ];
    }

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

    public function handlers(): array
    {
        return [
            [
                'handler' => 'ScraperEventHandler',
                'consumes' => [PipelineEvent::SCRAPE_REQUESTED],
                'responsibility' => 'Submit a Crawl4AI crawl and publish the first scrape.monitor.requested event.',
                'writes' => ['pipeline_jobs', 'pipeline_events', 'scrape history'],
                'publishes' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'handler' => 'ScrapeMonitorEventHandler',
                'consumes' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED],
                'responsibility' => 'Check Crawl4AI status once, reschedule running crawls, and emit page/file outputs.',
                'writes' => ['pipeline_jobs', 'pipeline_events', 'scraped_elements'],
                'publishes' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED, PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_DISCOVERED, PipelineEvent::JOB_FAILED],
            ],
            [
                'handler' => 'ConverterEventHandler',
                'consumes' => [PipelineEvent::FILE_DISCOVERED],
                'responsibility' => 'Convert supported files and publish converted markdown for ingestion.',
                'writes' => ['pipeline_jobs', 'pipeline_events'],
                'publishes' => [PipelineEvent::FILE_CONVERTED, PipelineEvent::JOB_FAILED],
            ],
            [
                'handler' => 'IngestionEventHandler',
                'consumes' => [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED],
                'responsibility' => 'Send page or converted-file content to the RAG bridge and record ingested documents.',
                'writes' => ['pipeline_jobs', 'pipeline_events', 'documents', 'job_processing_state'],
                'publishes' => [PipelineEvent::CONTENT_INGESTED, PipelineEvent::JOB_FAILED],
            ],
        ];
    }

    public function persistence(): array
    {
        return [
            [
                'table' => 'pipeline_tasks',
                'repository' => 'PipelineTaskRepository',
                'purpose' => 'User-facing pipeline run, dataset scope, counters, and task-level status.',
            ],
            [
                'table' => 'pipeline_jobs',
                'repository' => 'PipelineJobRepository',
                'purpose' => 'Individual scrape, convert, ingest, upload, and recovery jobs.',
            ],
            [
                'table' => 'pipeline_stage_states',
                'repository' => 'PipelineStageStateRepository',
                'purpose' => 'Stage progress snapshots for scrape, convert, ingest, and graph stages.',
            ],
            [
                'table' => 'pipeline_events',
                'repository' => 'PipelineEventRecorder',
                'purpose' => 'Timeline/audit events recorded even when RabbitMQ publish fails.',
            ],
            [
                'table' => 'job_processing_state',
                'repository' => 'PipelineIngestionRepository',
                'purpose' => 'Per-document ingestion status and source metadata.',
            ],
            [
                'table' => 'documents',
                'repository' => 'PipelineIngestionRepository',
                'purpose' => 'Documents visible in the document browser and dataset views.',
            ],
            [
                'table' => 'scraped_elements',
                'repository' => 'PipelineScrapeHistoryRepository',
                'purpose' => 'Scrape deduplication/history for already scraped URLs.',
            ],
        ];
    }

    public function idempotency(): array
    {
        return [
            [
                'area' => 'event normalization',
                'mechanism' => 'PipelineEvent generates deterministic job IDs from event type, task, source URL, local path, and content hash when no job ID is supplied.',
            ],
            [
                'area' => 'scrape history',
                'mechanism' => 'Scrape handlers can skip already-scraped URLs and record skipped jobs instead of redoing work.',
            ],
            [
                'area' => 'conversion',
                'mechanism' => 'Converter handling can reuse cached conversion metadata or skip database duplicates.',
            ],
            [
                'area' => 'recovery',
                'mechanism' => 'Recovery metadata includes idempotency keys and retry counters so repeated operator retries do not duplicate state transitions.',
            ],
        ];
    }

    public function recovery(): array
    {
        return [
            'entrypoints' => [
                'GET /api/pipeline/recovery/failed-jobs',
                'POST /api/pipeline/recovery/jobs/{jobId}/retry',
                'POST /api/pipeline/recovery/jobs/retry-selected',
                'POST /api/pipeline/recovery/tasks/{taskId}/retry-failed',
                'POST /api/pipeline/recovery/datasets/{datasetId}/retry-failed',
                'POST /api/pipeline/recovery/retry-all',
            ],
            'services' => [
                'PipelineRecoveryService',
                'PipelineRecoveryPayloadService',
                'PipelineRecoveryMetadataService',
                'PipelineRecoveryInputNormalizer',
            ],
            'principle' => 'Recovery republishes the original source event shape when possible and updates failed job metadata before publishing.',
        ];
    }

    public function health(): array
    {
        return [
            'commands' => [
                'php artisan pipeline:health',
                'php artisan pipeline:workers',
                'php artisan pipeline:declare-event-topology',
                'php artisan pipeline:smoke-test',
                'php artisan pipeline:architecture',
            ],
            'checks' => [
                'RabbitMQ management queues and worker queue presence',
                'Crawl4AI reachability for scrape work',
                'file converter reachability for conversion work',
                'RAG bridge reachability for ingestion work',
                'shared storage visibility and permissions',
                'Neo4j availability when graph mode is enabled',
            ],
        ];
    }

    public function testing(): array
    {
        return [
            [
                'layer' => 'unit',
                'purpose' => 'Pure services: event normalization, retry factories, payload shaping, topology summaries, direct ingest helpers.',
            ],
            [
                'layer' => 'feature',
                'purpose' => 'Controllers and commands with Laravel routing/config/container behavior.',
            ],
            [
                'layer' => 'db-backed feature',
                'purpose' => 'Repositories, state transitions, recovery flows, and pipeline event handlers using the real local database.',
            ],
            [
                'layer' => 'faked integration',
                'purpose' => 'RabbitMQ, HTTP, Crawl4AI, converter, and bridge interactions are mocked/faked to assert publish and request contracts.',
            ],
        ];
    }

    public function mentalModel(): array
    {
        return [
            'User/API creates a pipeline task.',
            'Laravel records task/job state in the database.',
            'Laravel publishes a RabbitMQ event for the next unit of work.',
            'A worker consumes exactly the event types configured for its queue.',
            'The handler writes durable state before or while publishing follow-up events.',
            'Retry queues delay transient failures and dead-letter back to the events exchange.',
            'Exhausted failures become job.failed events and are visible through recovery.',
            'Dashboards read database/status APIs; they do not inspect RabbitMQ directly for business state.',
        ];
    }

    private function consumersFor(string $eventType): array
    {
        $consumers = [];
        foreach ($this->config->workers() as $worker => $config) {
            if (! is_array($config)) {
                continue;
            }

            $events = array_values(array_filter(array_map('strval', $config['listen'] ?? [])));
            if (in_array($eventType, $events, true)) {
                $consumers[] = (string) $worker;
            }
        }

        if ($eventType === PipelineEvent::JOB_FAILED) {
            $consumers[] = 'failed_event_queue';
        }

        return $consumers;
    }
}
