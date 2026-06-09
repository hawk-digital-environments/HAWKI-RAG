<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Architecture;

use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\Pipeline\Events\PipelineEventTypeRegistry;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineArchitectureEventCatalog
{
    public function __construct(
        private PipelineEventTypeRegistry $types,
        private PipelineEventConfig $config,
    ) {
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
