<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Services\Scrape\Exceptions\ScrapeFinalizationException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class ScrapeElementPayloadBuilder
{
    public function __construct(
        private PipelineDataValidator $validator,
        private ScrapeElementUrlResolver $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $elementData
     * @return array<string, mixed>
     */
    public function build(array $elementData, string $urlHash, string $jobId, ?string $publishedAt): array
    {
        $pageUrl = $this->firstScalar($elementData['page_url'] ?? null);
        if (! $pageUrl) {
            throw ScrapeFinalizationException::missingPageUrl($urlHash);
        }

        $title = $this->firstScalar($elementData['title'] ?? null) ?? $this->urls->title($pageUrl);
        $images = is_array($elementData['images'] ?? null) ? $elementData['images'] : [];
        $pdfs = is_array($elementData['pdfs'] ?? null) ? $elementData['pdfs'] : [];
        $urlParts = $this->urls->parts($pageUrl);

        return [
            'uuid' => Str::uuid()->toString(),
            'title' => $title,
            'page_url' => $pageUrl,
            'meta_img_url' => $this->firstScalar($elementData['meta_img_url'] ?? null),
            'page_url_hash' => $urlHash,
            'content_hash' => $this->firstScalar($elementData['content_hash'] ?? null) ?? hash('sha256', $pageUrl),
            'language' => $elementData['lang'] ?? 'en',
            'images' => $images,
            'pdfs' => $pdfs,
            'published_at' => $publishedAt,
            'domain' => $urlParts['domain'],
            'subdomain' => $urlParts['subdomain'],
            'canonicalized_path' => $elementData['canonicalized_path'] ?? null,
            'access_level' => 'internal',
            'job_id' => $jobId,
            'image_count' => count($images),
            'pdf_count' => count($pdfs),
            'content_length' => $elementData['content_length'] ?? null,
            'fetch_time' => $elementData['fetch_time'] ?? null,
            'http_status' => $elementData['http_status'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $elementData
     */
    public function publishedDateSource(array $elementData): ?string
    {
        return $this->firstScalar($elementData['published_at'] ?? $elementData['date'] ?? null);
    }

    public function pageUrl(array $elementData): ?string
    {
        return $this->firstScalar($elementData['page_url'] ?? null);
    }

    public function firstScalar(mixed $value): ?string
    {
        return $this->validator->firstScalar($value);
    }
}
