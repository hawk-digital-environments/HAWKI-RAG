<?php

namespace App\Services\Crawler\Data;

/**
 * Data object containing processed URL information and metadata.
 *
 * This immutable object holds the results of URL processing, including the
 * original URL, its type (local file, sitemap, or direct), and extracted URLs
 * if applicable. Used throughout the crawler system to pass URL-related
 * information between services.
 *
 * @property-read string $url The original URL or file path provided
 * @property-read bool $isLocalFile Whether the input was a local file
 * @property-read string|null $baseUrl Base URL extracted from the first URL (for local files)
 * @property-read string $sourceType Type of source: 'local', 'sitemap', or 'direct'
 * @property-read array $sitemapUrls Array of URLs extracted from local file or sitemap
 */
class UrlProcessingOptions
{
    public function __construct(
        public readonly string $url,
        public readonly bool $isLocalFile,
        public readonly ?string $baseUrl,
        public readonly string $sourceType,
        public readonly array $sitemapUrls,
    ) {}

    /**
     * Check if the source type is a sitemap URL.
     *
     * @return bool True if source is a remote sitemap
     */
    public function isSitemap(): bool
    {
        return $this->sourceType === 'sitemap';
    }

    /**
     * Check if the source type is a local file.
     *
     * @return bool True if source is a local file containing URLs
     */
    public function isLocal(): bool
    {
        return $this->sourceType === 'local';
    }

    /**
     * Check if the source type is a direct URL.
     *
     * @return bool True if source is a direct URL to crawl
     */
    public function isDirect(): bool
    {
        return $this->sourceType === 'direct';
    }

    /**
     * Check if URLs were extracted from the source.
     *
     * @return bool True if sitemapUrls array contains one or more URLs
     */
    public function hasUrls(): bool
    {
        return count($this->sitemapUrls) > 0;
    }
}
