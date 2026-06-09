<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Repositories\ScrapedElementRepository;
use App\Services\Scrape\Repositories\ScrapeStatisticsRepository;
use App\Services\Storage\StorageService;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

#[Singleton]
class ScrapeDatasetCreator
{
    public function __construct(
        protected readonly StorageService $storageService,
        protected readonly PipelineDataValidator $validator,
        protected readonly PipelineStageLogger $logger,
        protected readonly ScrapedElementRepository $elements,
        protected readonly ScrapeStatisticsRepository $statistics,
        private readonly ScrapeElementPayloadBuilder $payloads,
        private readonly ScrapeElementDateNormalizer $dates,
    ) {}

    public function createElementData(ScrapeContext $context, string $urlHash): void
    {
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);
        try {
            $validation = $this->validator->validateScrapeElement($elementData);
            if ($validation['errors'] !== []) {
                $message = implode('; ', $validation['errors']);
                $this->logger->validationFailed('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'element_metadata',
                    'error_message' => $message,
                    'errors' => $validation['errors'],
                    'warnings' => $validation['warnings'],
                ]);
                $context->addError("Invalid scraped element {$urlHash}: {$message}");

                return;
            }

            if ($validation['warnings'] !== []) {
                $this->logger->partial('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'element_metadata',
                    'warnings' => $validation['warnings'],
                ]);
                foreach ($validation['warnings'] as $warning) {
                    $context->addWarning("Scraped element {$urlHash}: {$warning}");
                }
            }

            $dateSource = $this->payloads->publishedDateSource($elementData);
            $publishedAt = $this->dates->normalize($dateSource);
            if ($dateSource !== null && $publishedAt === null) {
                $this->logger->partial('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'element_metadata',
                    'error_message' => "Invalid published_at date: {$dateSource}",
                ]);
            }

            $attributes = $this->payloads->build($elementData, $urlHash, $context->jobId, $publishedAt);
            $this->elements->create($attributes);

            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'source_url' => $attributes['page_url'],
                'title' => $attributes['title'],
                'pipeline_stage' => 'element_persisted',
                'image_count' => $attributes['image_count'],
                'pdf_count' => $attributes['pdf_count'],
            ]);
        } catch (Throwable $exception) {
            $this->logger->failed('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'pipeline_stage' => 'element_persisted',
                'error_message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $context->addError($exception->getMessage());
        }

    }

    public function recordScrapeSummary(ScrapeContext $context, array $summary): void
    {
        $statistics = $summary['statistics'] ?? [];
        $timing = $summary['timing'] ?? [];
        if (! is_array($statistics) || ! is_array($timing)) {
            $this->logger->validationFailed('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'summary',
                'error_message' => 'Scrape summary statistics or timing block is invalid.',
            ]);
            $context->addWarning('Scrape summary statistics or timing block is invalid.');
            $statistics = [];
            $timing = [];
        }

        $this->statistics->updateOrCreateForJob($context->jobId, [
            'sessions' => $statistics['sessions'] ?? 0,
            'requests' => $statistics['requests'] ?? 0,
            'errors' => array_merge($context->getErrors(), is_array($statistics['errors'] ?? null) ? $statistics['errors'] : []),
            'warnings' => array_merge($context->getWarnings(), is_array($statistics['warnings'] ?? null) ? $statistics['warnings'] : []),
            'pdfs_downloaded' => $statistics['pdfs_downloaded'] ?? 0,
            'images_downloaded' => $statistics['images_downloaded'] ?? 0,
            'started_at' => $timing['started_at'] ?? null,
            'completed_at' => $timing['completed_at'] ?? null,
        ]);

        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'summary_recorded',
            'requests' => $statistics['requests'] ?? 0,
            'pdfs_downloaded' => $statistics['pdfs_downloaded'] ?? 0,
            'images_downloaded' => $statistics['images_downloaded'] ?? 0,
        ]);
    }

}
