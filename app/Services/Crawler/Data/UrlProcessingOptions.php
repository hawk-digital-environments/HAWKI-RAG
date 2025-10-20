<?php

namespace App\Services\Crawler\Data;

class UrlProcessingOptions
{
    public function __construct(
        public readonly string $url,
        public readonly bool $isLocalFile,
        public readonly ?string $baseUrl,
        public readonly string $sourceType,
        public readonly array $sitemapUrls,
    ) {}

    public function isSitemap(): bool
    {
        return $this->sourceType === 'sitemap';
    }

    public function isLocal(): bool
    {
        return $this->sourceType === 'local';
    }

    public function isDirect(): bool
    {
        return $this->sourceType === 'direct';
    }

    public function hasUrls(): bool
    {
        return count($this->sitemapUrls) > 0;
    }
}
