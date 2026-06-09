<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Exceptions\ScrapeFinalizationException;
use App\Services\Scrape\Repositories\ScrapedElementRepository;
use App\Services\Storage\StorageService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeElementReconciler
{
    public function __construct(
        private StorageService $storageService,
        private PipelineDataValidator $validator,
        private PipelineStageLogger $logger,
        private ScrapedElementRepository $elements,
        private ScrapeElementPayloadBuilder $payloads,
        private ScrapeElementDateNormalizer $dates,
    ) {}

    public function reconcile(ScrapeContext $context): void
    {
        $diskUrls = $this->completedUrls($context->jobId);
        $totalUrls = count($diskUrls);
        $existingElements = $this->elements->pageUrlHashesForJob($context->jobId);

        $existingCount = count($existingElements);
        $syncedCount = 0;
        $createdCount = 0;
        $errorCount = 0;

        foreach ($diskUrls as $urlData) {
            try {
                $urlHash = $urlData['url_hash'] ?? null;

                if (! $urlHash) {
                    $errorCount++;
                    $this->logger->validationFailed('scrape', [
                        'job_id' => $context->jobId,
                        'pipeline_stage' => 'finalization',
                        'error_message' => 'Missing url_hash in disk data.',
                        'url_data' => $urlData,
                    ]);

                    continue;
                }

                if (in_array($urlHash, $existingElements, true)) {
                    $syncedCount++;
                    $this->logger->skipped('scrape', [
                        'job_id' => $context->jobId,
                        'doc_id' => $urlHash,
                        'pipeline_stage' => 'finalization',
                        'reason' => 'Scraped element already exists.',
                    ]);

                    continue;
                }

                $this->createMissingElement($context, (string) $urlHash);
                $createdCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $this->logger->failed('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlData['url_hash'] ?? 'unknown',
                    'pipeline_stage' => 'finalization',
                    'error_message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $context->addWarning('Failed to sync element: '.$e->getMessage());
            }
        }

        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'finalization_checkup',
            'total_urls' => $totalUrls,
            'existing_elements' => $existingCount,
            'synced' => $syncedCount,
            'created' => $createdCount,
            'errors' => $errorCount,
        ]);

        if ($errorCount > 0) {
            $context->addWarning("Finalization completed with {$errorCount} errors");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completedUrls(string $jobId): array
    {
        $completedUrls = [];
        foreach ($this->storageService->fetchUrlsList($jobId) as $urlData) {
            if (isset($urlData['status']) && $urlData['status'] === 'completed') {
                $completedUrls[] = $urlData;
            }
        }

        return $completedUrls;
    }

    private function createMissingElement(ScrapeContext $context, string $urlHash): void
    {
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);
        $validation = $this->validator->validateScrapeElement($elementData);
        if ($validation['errors'] !== []) {
            throw ScrapeFinalizationException::invalidScrapedElement($urlHash, $validation['errors']);
        }

        if ($validation['warnings'] !== []) {
            $this->logger->partial('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'pipeline_stage' => 'finalization',
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
                'pipeline_stage' => 'finalization',
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
            'pipeline_stage' => 'finalization_element_persisted',
        ]);
    }
}
