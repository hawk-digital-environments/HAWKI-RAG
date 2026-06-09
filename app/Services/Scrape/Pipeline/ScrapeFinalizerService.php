<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Models\ScrapedElement;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Storage\StorageService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Singleton]
class ScrapeFinalizerService
{
    public function __construct(
        protected readonly StorageService $storageService,
        protected readonly PipelineDataValidator $validator,
        protected readonly PipelineStageLogger $logger,
    ) {}

    public function executeFinalization(ScrapeContext $context): void
    {
        try {
            $this->logger->started('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
            ]);

            $this->checkupElements($context);
            $context->setStage('Scrape-Completed');
            $context->setStats('completed_at', now());
            Cache::forget("scrape_process:{$context->jobId}");
            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
            ]);

        } catch (\Exception $e) {
            $this->logger->failed('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $context->addError('Finalization failed: '.$e->getMessage());
            throw $e;
        }
    }

    protected function checkupElements(ScrapeContext $context): void
    {
        // Log::info("Starting element checkup for job: {$context->jobId}");

        // Get completed URLs from disk
        $diskUrls = $this->getListOfCompletedUrls($context->jobId);
        $totalUrls = count($diskUrls);

        // Log::info("Found {$totalUrls} completed URLs on disk for job: {$context->jobId}");

        // Get existing elements from database for this job
        $existingElements = ScrapedElement::where('job_id', $context->jobId)
            ->pluck('page_url_hash')
            ->toArray();

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

                // Check if element exists in database
                if (in_array($urlHash, $existingElements)) {
                    $syncedCount++;
                    $this->logger->skipped('scrape', [
                        'job_id' => $context->jobId,
                        'doc_id' => $urlHash,
                        'pipeline_stage' => 'finalization',
                        'reason' => 'Scraped element already exists.',
                    ]);

                    continue;
                }

                // Element is missing from database - create it
                // Log::info("Creating missing element in database: {$urlHash}");
                $this->createMissingElement($context, $urlHash);
                $createdCount++;

            } catch (\Exception $e) {
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

    protected function getListOfCompletedUrls(string $jobId): array
    {
        $diskUrls = $this->storageService->fetchUrlsList($jobId);

        // The URLs come as objects with URL keys, we need to filter by status
        $completedUrls = [];
        foreach ($diskUrls as $urlData) {
            if (isset($urlData['status']) && $urlData['status'] === 'completed') {
                $completedUrls[] = $urlData;
            }
        }

        return $completedUrls;
    }

    /**
     * Create a missing ScrapedElement from disk data.
     *
     * @throws \Exception
     */
    protected function createMissingElement(ScrapeContext $context, string $urlHash): void
    {
        // Fetch element data from disk
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);
        $validation = $this->validator->validateScrapeElement($elementData);
        if ($validation['errors'] !== []) {
            throw new \Exception("Invalid scraped element {$urlHash}: ".implode('; ', $validation['errors']));
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

        // Helper function to extract first element if arrayed, otherwise return as-is
        $extractValue = fn ($value) => $this->validator->firstScalar($value);

        // Extract scalar values from arrays
        $pageUrl = $extractValue($elementData['page_url'] ?? null);
        $title = $extractValue($elementData['title'] ?? null) ?? $this->titleFromUrl($pageUrl);
        $metaImgUrl = $extractValue($elementData['meta_img_url'] ?? null);
        $publishedAt = $extractValue($elementData['published_at'] ?? $elementData['date'] ?? null);

        if (! $pageUrl) {
            throw new \Exception("page_url is missing or empty in disk data for url_hash: {$urlHash}");
        }

        // Parse published date
        if ($publishedAt) {
            try {
                $publishedAt = (new \DateTime($publishedAt))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $this->logger->partial('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'finalization',
                    'error_message' => "Invalid published_at date: {$publishedAt}",
                ]);
                $publishedAt = null;
            }
        }

        // Extract URL parts (domain, subdomain)
        $urlParts = $this->explodeUrl($pageUrl);

        // Ensure images and pdfs are arrays
        $images = $elementData['images'] ?? [];
        $pdfs = $elementData['pdfs'] ?? [];

        // Create the ScrapedElement
        ScrapedElement::create([
            'uuid' => Str::uuid()->toString(),
            'title' => $title,
            'page_url' => $pageUrl,
            'meta_img_url' => $metaImgUrl,
            'page_url_hash' => $urlHash,
            'content_hash' => $this->validator->firstScalar($elementData['content_hash'] ?? null) ?? hash('sha256', $pageUrl),
            'language' => $elementData['lang'] ?? 'en',
            'images' => is_array($images) ? json_encode($images) : $images,
            'pdfs' => is_array($pdfs) ? json_encode($pdfs) : $pdfs,
            'published_at' => $publishedAt,
            'domain' => $urlParts['domain'],
            'subdomain' => $urlParts['subdomain'],
            'canonicalized_path' => $elementData['canonicalized_path'] ?? null,
            'access_level' => 'internal',
            'job_id' => $context->jobId,
            'image_count' => is_array($images) ? count($images) : 0,
            'pdf_count' => is_array($pdfs) ? count($pdfs) : 0,
            'content_length' => $elementData['content_length'] ?? null,
            'fetch_time' => $elementData['fetch_time'] ?? null,
            'http_status' => $elementData['http_status'] ?? null,
        ]);

        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'doc_id' => $urlHash,
            'source_url' => $pageUrl,
            'title' => $title,
            'pipeline_stage' => 'finalization_element_persisted',
        ]);

        // Log::info("Successfully created missing element", [
        //            'job_id' => $context->jobId,
        //            'url_hash' => $urlHash,
        //            'page_url' => $pageUrl
        //        ]);
    }

    /**
     * Parse URL to extract domain and subdomain.
     */
    protected function explodeUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return [
                'subdomain' => '',
                'domain' => '',
                'full_domain' => '',
            ];
        }

        $parts = explode('.', $host);
        $partCount = count($parts);

        // Handle different domain structures
        if ($partCount >= 2) {
            $subdomainParts = array_slice($parts, 0, $partCount - 2);
            $subdomain = implode('.', $subdomainParts);
            $domain = implode('.', array_slice($parts, $partCount - 2));
        } else {
            $subdomain = '';
            $domain = $host;
        }

        return [
            'subdomain' => $subdomain,
            'domain' => $domain,
            'full_domain' => $host,
        ];
    }

    private function titleFromUrl(?string $url): string
    {
        if (! $url) {
            return 'Untitled document';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path !== '') {
            return basename($path);
        }

        return parse_url($url, PHP_URL_HOST) ?: 'Untitled document';
    }
}
