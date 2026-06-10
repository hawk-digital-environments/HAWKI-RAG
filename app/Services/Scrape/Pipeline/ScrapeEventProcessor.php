<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeEventPacket;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeEventProcessor
{
    public function __construct(
        private ScrapeContextBuilder $contexts,
        private ScrapeDatasetCreator $datasetCreator,
        private ScrapeFinalizerService $finalizer,
        private PipelineStageLogger $logger,
    ) {}

    public function process(ScrapeEventPacket $packet): void
    {
        $context = $this->contexts->rebuildContext($packet->jobId);
        $this->logger->started('scrape', [
            'job_id' => $packet->jobId,
            'pipeline_stage' => 'event',
            'event_name' => $packet->event,
        ]);

        match ($packet->event) {
            'stage' => $this->processStageChange($packet, $context),
            'report' => $this->processJobReport($packet, $context),
            'summary' => $this->processSummary($packet, $context),
            default => $this->logger->skipped('scrape', [
                'job_id' => $packet->jobId,
                'pipeline_stage' => 'event',
                'event_name' => $packet->event,
                'reason' => 'Unsupported scrape event type.',
            ]),
        };
    }

    private function processStageChange(ScrapeEventPacket $packet, ScrapeContext $context): void
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

        if ($stage !== 'sitemap_detected') {
            return;
        }

        $totalUrls = $packet->data['details']['total_urls'] ?? null;
        if (is_numeric($totalUrls)) {
            $context->setStats('total_urls', (int) $totalUrls);

            return;
        }

        $this->logger->partial('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'stage_change',
            'crawler_stage' => $stage,
            'error_message' => 'sitemap_detected stage is missing total_urls.',
        ]);
        $context->addWarning('sitemap_detected stage is missing total_urls.');
    }

    private function processJobReport(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        if (array_key_exists('stats', $packet->data) && is_array($packet->data['stats'])) {
            foreach ($packet->data['stats'] as $name => $value) {
                $context->setStats((string) $name, $value);
            }
        }

        if (! array_key_exists('url_completion', $packet->data) || ! is_array($packet->data['url_completion'])) {
            return;
        }

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

    private function processSummary(ScrapeEventPacket $packet, ScrapeContext $context): void
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
