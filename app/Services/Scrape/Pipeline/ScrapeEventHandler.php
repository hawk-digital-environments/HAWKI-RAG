<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeEventPacket;
use Exception;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ScrapeEventHandler
{
    public function __construct(
        private readonly ScrapeContextBuilder $contexts,
        private readonly ScrapeDatasetCreator $datasetCreator,
        private readonly ScrapeFinalizerService $finalizer,
        private readonly PipelineStageLogger $logger,
    ) {}

    public function handle(array $payload)
    {
        // Validate message structure
        if (! $this->isValidEventPacket($payload)) {
            $this->logger->validationFailed('scrape', [
                'job_id' => $payload['job_id'] ?? null,
                'pipeline_stage' => 'event_packet',
                'error_message' => 'Invalid scrape event packet structure.',
                'event_name' => $payload['event'] ?? null,
                'payload_keys' => array_keys($payload),
            ]);

            return;
        }
        // Create ScrapeEventPacket from the incoming message
        $packet = $this->createScrapeEventPacket($payload);

        // Process the event using the existing handler
        $this->processEvent($packet);
    }

    /**
     * Validate event packet structure.
     */
    protected function isValidEventPacket(array $data): bool
    {
        return isset($data['job_id']) &&
            isset($data['event']) &&
            isset($data['data']) &&
            isset($data['timestamp']) &&
            is_string($data['job_id']) &&
            is_string($data['event']) &&
            is_array($data['data']) &&
            is_string($data['timestamp']);
    }

    /**
     * Create a ScrapeEventPacket from decoded payload.
     */
    protected function createScrapeEventPacket(array $data): ScrapeEventPacket
    {
        return new ScrapeEventPacket(
            jobId: $data['job_id'],
            event: $data['event'],
            data: $data['data'],
            timestamp: $data['timestamp']
        );
    }

    /**
     * Process a validated event packet.
     *
     * @throws Exception
     */
    protected function processEvent(ScrapeEventPacket $packet): void
    {
        // Rebuild context from job ID
        $context = $this->contexts->rebuildContext($packet->jobId);
        $this->logger->started('scrape', [
            'job_id' => $packet->jobId,
            'pipeline_stage' => 'event',
            'event_name' => $packet->event,
        ]);

        switch ($packet->event) {
            case 'stage':
                $this->processStageChange($packet, $context);
                break;
            case 'report':
                $this->processJobReport($packet, $context);
                break;
            case 'summary':
                $this->processSummary($packet, $context);
                break;
            default:
                $this->logger->skipped('scrape', [
                    'job_id' => $packet->jobId,
                    'pipeline_stage' => 'event',
                    'event_name' => $packet->event,
                    'reason' => 'Unsupported scrape event type.',
                ]);
        }
    }

    protected function processStageChange(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        $stage = $packet->data['stage'] ?? null;
        if (! is_string($stage) || trim($stage) === '') {
            $this->logger->validationFailed('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'stage_change',
                'error_message' => 'Stage event is missing a valid stage value.',
            ]);
            $context->addError('Stage event is missing a valid stage value.');

            return;
        }

        $context->setStage($stage);
        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'stage_change',
            'crawler_stage' => $stage,
        ]);
        if ($stage === 'sitemap_detected') {
            $totalUrls = $packet->data['details']['total_urls'] ?? null;
            if (is_numeric($totalUrls)) {
                $context->setStats('total_urls', (int) $totalUrls);
            } else {
                $this->logger->partial('scrape', [
                    'job_id' => $context->jobId,
                    'pipeline_stage' => 'stage_change',
                    'crawler_stage' => $stage,
                    'error_message' => 'sitemap_detected stage is missing total_urls.',
                ]);
                $context->addWarning('sitemap_detected stage is missing total_urls.');
            }
        }
    }

    /**
     * @throws Exception
     */
    protected function processJobReport(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        if (array_key_exists('stats', $packet->data)) {
            foreach ($packet->data['stats'] as $name => $value) {
                $context->setStats($name, $value);
            }
        }
        if (array_key_exists('url_completion', $packet->data)) {
            $completion = $packet->data['url_completion'];
            $url = $completion['url'] ?? null;
            $urlHash = $completion['url_hash'] ?? null;
            if (! is_string($url) || trim($url) === '' || ! is_string($urlHash) || trim($urlHash) === '') {
                $this->logger->validationFailed('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => is_scalar($urlHash) ? (string) $urlHash : null,
                    'source_url' => is_scalar($url) ? (string) $url : null,
                    'pipeline_stage' => 'url_completion',
                    'error_message' => 'url_completion is missing url or url_hash.',
                ]);
                $context->addError('url_completion is missing url or url_hash.');

                return;
            }

            $context->setStats('current_url', $url);
            $this->logger->started('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'source_url' => $url,
                'pipeline_stage' => 'url_completion',
            ]);
            $this->datasetCreator->createElementData($context, $urlHash);
        }
    }

    protected function processSummary(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        $this->datasetCreator->recordScrapeSummary($context, $packet->data);
        $context->setEndProcess(true);
        $this->finalizer->executeFinalization($context);
        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'summary',
            'statistics' => $packet->data['statistics'] ?? [],
        ]);
    }
}
