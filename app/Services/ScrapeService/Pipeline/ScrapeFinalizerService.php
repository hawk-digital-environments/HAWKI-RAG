<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapedElement;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\StorageService\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class ScrapeFinalizerService
{

    protected StorageService $storageService;
    public function __construct(
    )
    {
        $this->storageService = app(StorageService::class);
    }

    public function executeFinalization(ScrapeContext $context): void
    {
        try {
            Log::info("Starting finalization for job: {$context->jobId}");

            // Fetch and store summary
            $summary = $this->storageService->fetchJobReport($context->jobId, 'summary');
            $context->addMetadata('summary', $summary);
            Log::info("Summary fetched for job: {$context->jobId}");

            // Get completed URLs
            $list = $this->getListOfCompletedUrls($context->jobId);
            Log::info("Found " . count($list) . " completed URLs for job: {$context->jobId}");

            // Create database entries for each completed URL
            $successCount = 0;
            $errorCount = 0;

            foreach ($list as $url) {
                try {
                    $this->createElementData($context, $url['url_hash']);
                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error("Failed to create element for url_hash: {$url['url_hash']}", [
                        'job_id' => $context->jobId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info("Finalization completed for job: {$context->jobId}", [
                'success' => $successCount,
                'errors' => $errorCount
            ]);

            $context->setStage('completed');
            Cache::forget("scrape_process:{$context->jobId}");

        } catch (\Exception $e) {
            Log::error("Finalization failed for job: {$context->jobId}", [
                'error' => $e->getMessage(),
                'exception' => $e
            ]);
            $context->addError("Finalization failed: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getListOfCompletedUrls(string $jobId): array
    {
        $urls = $this->storageService->fetchUrlsList($jobId);

        // The URLs come as objects with URL keys, we need to filter by status
        $completedUrls = [];
        foreach ($urls as $urlData) {
            if (isset($urlData['status']) && $urlData['status'] === 'completed') {
                $completedUrls[] = $urlData;
            }
        }

        return $completedUrls;
    }


    protected function createElementData(ScrapeContext $context, string $urlHash): void
    {
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);

        try{
            // Helper function to extract first element if array, otherwise return as-is
            $extractValue = fn($value) => is_array($value) ? ($value[0] ?? null) : $value;

            // Extract scalar values from arrays
            $pageUrl = $extractValue($elementData['page_url']);
            $title = $extractValue($elementData['title'] ?? null);
            $metaImgUrl = $extractValue($elementData['meta_img_url'] ?? null);
            $publishedAt = $extractValue($elementData['published_at'] ?? $elementData['date'] ?? null);

            if (!$pageUrl) {
                throw new \Exception("page_url is missing or empty");
            }

            $urlParts = $this->explodeUrl($pageUrl);

            // Ensure images and pdfs are arrays before encoding
            $images = $elementData['images'] ?? [];
            $pdfs = $elementData['pdfs'] ?? [];

            ScrapedElement::create([
                'uuid' => Str::uuid()->toString(),
                'title' => $title,
                'page_url' => $pageUrl,
                'meta_img_url' => $metaImgUrl,
                'page_url_hash' => $elementData['url_hash'], // Crawler uses 'url_hash'
                'content_hash' => $elementData['content_hash'],
                'language' => $elementData['lang'] ?? 'en',
                'images' => is_array($images) ? json_encode($images) : $images,
                'pdfs' => is_array($pdfs) ? json_encode($pdfs) : $pdfs,
                'published_at' => $publishedAt,
                'domain' => $urlParts['domain'],
                'subdomain' => $urlParts['subdomain'],
                'full_domain' => $urlParts['full_domain'],
                'access_level' => 'internal',
                'scrape_job_id' => $context->jobId,
                'image_count' => is_array($images) ? count($images) : 0,
                'pdf_count' => is_array($pdfs) ? count($pdfs) : 0,
                'content_length' => $elementData['content_length'] ?? null,
            ]);
        }
        catch (\Exception $exception){
            Log::error("Failed to create scraped element: " . $exception->getMessage(), [
                'job_id' => $context->jobId,
                'url_hash' => $urlHash,
                'exception' => $exception
            ]);
            $context->addError($exception->getMessage());
        }

    }

    public function explodeUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $parts = explode('.', $host);
        $subdomainParts = array_slice($parts, 0, count($parts) - 2);
        $subdomain = implode('.', $subdomainParts);
        $domain = implode( '.', array_slice($parts, count($subdomainParts)));
        return [
            'subdomain' => $subdomain,
            'domain' => $domain,
            'full_domain' => $host,
        ];
    }

}
