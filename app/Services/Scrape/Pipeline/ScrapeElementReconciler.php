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
use Illuminate\Support\Str;

#[Singleton]
readonly class ScrapeElementReconciler
{
    public function __construct(
        private StorageService $storageService,
        private PipelineDataValidator $validator,
        private PipelineStageLogger $logger,
        private ScrapedElementRepository $elements,
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

        $extractValue = fn ($value) => $this->validator->firstScalar($value);
        $pageUrl = $extractValue($elementData['page_url'] ?? null);
        $title = $extractValue($elementData['title'] ?? null) ?? $this->titleFromUrl($pageUrl);
        $metaImgUrl = $extractValue($elementData['meta_img_url'] ?? null);
        $publishedAt = $extractValue($elementData['published_at'] ?? $elementData['date'] ?? null);

        if (! $pageUrl) {
            throw ScrapeFinalizationException::missingPageUrl($urlHash);
        }

        if ($publishedAt) {
            try {
                $publishedAt = (new \DateTimeImmutable((string) $publishedAt))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $this->logger->partial('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'finalization',
                    'error_message' => "Invalid published_at date: {$publishedAt}",
                ]);
                $publishedAt = null;
            }
        }

        $urlParts = $this->explodeUrl((string) $pageUrl);
        $images = $elementData['images'] ?? [];
        $pdfs = $elementData['pdfs'] ?? [];

        $this->elements->create([
            'uuid' => Str::uuid()->toString(),
            'title' => $title,
            'page_url' => $pageUrl,
            'meta_img_url' => $metaImgUrl,
            'page_url_hash' => $urlHash,
            'content_hash' => $this->validator->firstScalar($elementData['content_hash'] ?? null) ?? hash('sha256', (string) $pageUrl),
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
    }

    /**
     * @return array{subdomain: string, domain: string, full_domain: string}
     */
    private function explodeUrl(string $url): array
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
