<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapedElement;
use App\Models\ScrapeStatistics;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\StorageService\StorageService;
use DateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeDatasetCreator
{
    protected StorageService $storageService;
    public function __construct(
    )
    {
        $this->storageService = app(StorageService::class);
    }

    /**
     * @throws \Exception
     */
    public function createElementData(ScrapeContext $context, string $urlHash): void
    {
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);
        try{
            // Helper function to extract first element if arrayed, otherwise return as-is
            $extractValue = fn($value) => is_array($value) ? ($value[0] ?? null) : $value;

            // Extract scalar values from arrays
            $pageUrl = $extractValue($elementData['page_url']);
            $title = $extractValue($elementData['title'] ?? null);
            $metaImgUrl = $extractValue($elementData['meta_img_url'] ?? null);
            $publishedAt = $extractValue($elementData['published_at'] ?? $elementData['date'] ?? null);
            $publishedAt = new DateTime($publishedAt)->format('Y-m-d H:i:s');;


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
                'canonicalized_path' => $elementData['canonicalized_path'],
                'access_level' => 'internal',
                'job_id' => $context->jobId,
                'image_count' => is_array($images) ? count($images) : 0,
                'pdf_count' => is_array($pdfs) ? count($pdfs) : 0,
                'content_length' => $elementData['content_length'] ?? null,
                'fetch_time' => $elementData['fetch_time'],
                'http_status' => $elementData['http_status'],
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

    protected function explodeUrl(string $url): array
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


    public function recordScrapeSummary(ScrapeContext $context, array $summary): void {
        ScrapeStatistics::updateOrCreate(
            ['job_id' => $context->jobId],
            [
            'sessions'=> $summary['statistics']['sessions'],
            'requests'=> $summary['statistics']['requests'],
            'errors'=> $summary['statistics']['errors'],
            'warnings'=> $summary['statistics']['warnings'],
            'pdfs_downloaded'=> $summary['statistics']['pdfs_downloaded'],
            'images_downloaded'=> $summary['statistics']['images_downloaded'],
            'started_at'=> $summary['timing']['started_at'],
            'completed_at'=> $summary['timing']['completed_at'],
        ]);
    }

}
