<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapedElement;
use App\Models\ScrapeStatistics;
use App\Services\Pipeline\PipelineDataValidator;
use App\Services\Pipeline\PipelineLogger;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\StorageService\StorageService;
use DateTime;
use Illuminate\Support\Str;

class ScrapeDatasetCreator
{
    public function __construct(
        protected StorageService $storageService,
        protected PipelineDataValidator $validator,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function createElementData(ScrapeContext $context, string $urlHash): void
    {
        $elementData = $this->storageService->fetchElementData($context->jobId, $urlHash);
        try{
            $validation = $this->validator->validateScrapeElement($elementData);
            if ($validation['errors'] !== []) {
                $message = implode('; ', $validation['errors']);
                PipelineLogger::validationFailed('scrape', [
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
                PipelineLogger::partial('scrape', [
                    'job_id' => $context->jobId,
                    'doc_id' => $urlHash,
                    'pipeline_stage' => 'element_metadata',
                    'warnings' => $validation['warnings'],
                ]);
                foreach ($validation['warnings'] as $warning) {
                    $context->addWarning("Scraped element {$urlHash}: {$warning}");
                }
            }

            // Helper function to extract first element if arrayed, otherwise return as-is
            $extractValue = fn($value) => $this->validator->firstScalar($value);

            // Extract scalar values from arrays
            $pageUrl = $extractValue($elementData['page_url']);
            $title = $extractValue($elementData['title'] ?? null) ?? $this->titleFromUrl($pageUrl);
            $metaImgUrl = $extractValue($elementData['meta_img_url'] ?? null);
            $publishedAt = $extractValue($elementData['published_at'] ?? $elementData['date'] ?? null);
            $publishedAt = $this->normalizeDate($publishedAt, $context->jobId, $urlHash);

            $urlParts = $this->explodeUrl($pageUrl);

            // Ensure images and pdfs are arrays before encoding
            $images = $elementData['images'] ?? [];
            $pdfs = $elementData['pdfs'] ?? [];

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

            PipelineLogger::success('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'source_url' => $pageUrl,
                'title' => $title,
                'pipeline_stage' => 'element_persisted',
                'image_count' => is_array($images) ? count($images) : 0,
                'pdf_count' => is_array($pdfs) ? count($pdfs) : 0,
            ]);
        }
        catch (\Exception $exception){
            PipelineLogger::failed('scrape', [
                'job_id' => $context->jobId,
                'doc_id' => $urlHash,
                'pipeline_stage' => 'element_persisted',
                'error_message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $context->addError($exception->getMessage());
        }

    }

    protected function explodeUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return [
                'subdomain' => '',
                'domain' => '',
                'full_domain' => '',
            ];
        }

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
        $statistics = $summary['statistics'] ?? [];
        $timing = $summary['timing'] ?? [];
        if (!is_array($statistics) || !is_array($timing)) {
            PipelineLogger::validationFailed('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'summary',
                'error_message' => 'Scrape summary statistics or timing block is invalid.',
            ]);
            $context->addWarning('Scrape summary statistics or timing block is invalid.');
            $statistics = [];
            $timing = [];
        }

        ScrapeStatistics::updateOrCreate(
            ['job_id' => $context->jobId],
            [
            'sessions'=> $statistics['sessions'] ?? 0,
            'requests'=> $statistics['requests'] ?? 0,
            'errors'=> array_merge($context->getErrors(), is_array($statistics['errors'] ?? null) ? $statistics['errors'] : []),
            'warnings'=> array_merge($context->getWarnings(), is_array($statistics['warnings'] ?? null) ? $statistics['warnings'] : []),
            'pdfs_downloaded'=> $statistics['pdfs_downloaded'] ?? 0,
            'images_downloaded'=> $statistics['images_downloaded'] ?? 0,
            'started_at'=> $timing['started_at'] ?? null,
            'completed_at'=> $timing['completed_at'] ?? null,
        ]);

        PipelineLogger::success('scrape', [
            'job_id' => $context->jobId,
            'pipeline_stage' => 'summary_recorded',
            'requests' => $statistics['requests'] ?? 0,
            'pdfs_downloaded' => $statistics['pdfs_downloaded'] ?? 0,
            'images_downloaded' => $statistics['images_downloaded'] ?? 0,
        ]);
    }

    private function normalizeDate(?string $value, string $jobId, string $docId): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return (new DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            PipelineLogger::partial('scrape', [
                'job_id' => $jobId,
                'doc_id' => $docId,
                'pipeline_stage' => 'element_metadata',
                'error_message' => "Invalid published_at date: {$value}",
            ]);
            return null;
        }
    }

    private function titleFromUrl(?string $url): string
    {
        if (!$url) {
            return 'Untitled document';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path !== '') {
            return basename($path);
        }

        return parse_url($url, PHP_URL_HOST) ?: 'Untitled document';
    }

}
