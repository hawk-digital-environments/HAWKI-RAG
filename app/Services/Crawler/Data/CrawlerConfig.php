<?php

namespace App\Services\Crawler\Data;

/**
 * Complete configuration data for a crawler execution.
 *
 * This immutable object contains all settings and parameters needed to execute
 * a web crawling operation. It's built by the configuration service and passed
 * to the execution service, which converts it to JSON for the Node.js crawler.
 *
 * @property-read string $url Target URL or base URL to crawl
 * @property-read int $maxPages Maximum number of pages to crawl (0 = unlimited)
 * @property-read string $outputDir Base directory for storing crawled data
 * @property-read string $label Label/prefix for organizing this crawl's directories
 * @property-read bool $skipImages Whether to skip downloading images
 * @property-read int $startFromIndex Index to start crawling from (1-based)
 * @property-read array $incompleteDirectories Map of incomplete directory numbers to their URLs
 * @property-read array $emptyDirectoriesToReuse Array of incomplete directory numbers without URL info
 * @property-read string $sourceType Type of source: 'local', 'sitemap', or 'direct'
 * @property-read array|null $imageExceptions URLs of images to download even when skipImages is true
 * @property-read string|null $dateSelector CSS selector for extracting publication dates
 * @property-read array|null $urls Explicit list of URLs to crawl (for local file sources)
 * @property-read bool $isLocalFile Whether this is a local file source
 * @property-read int|null $continueOffset Number of URLs to skip for continuation (remote sources)
 */
class CrawlerConfig
{
    public function __construct(
        public readonly string $url,
        public readonly int $maxPages,
        public readonly string $outputDir,
        public readonly string $label,
        public readonly bool $skipImages,
        public readonly int $startFromIndex,
        public readonly array $incompleteDirectories,
        public readonly array $emptyDirectoriesToReuse,
        public readonly string $sourceType,
        public readonly ?array $imageExceptions = null,
        public readonly ?string $dateSelector = null,
        public readonly ?array $urls = null,
        public readonly bool $isLocalFile = false,
        public readonly ?int $continueOffset = null,
    ) {}

    /**
     * Convert the configuration to an associative array.
     *
     * Serializes the configuration object to an array format suitable for
     * JSON encoding. Only includes optional fields if they have values.
     * Used internally by toJson() method.
     *
     * @return array Configuration as associative array
     */
    public function toArray(): array
    {
        // Build base configuration array
        $config = [
            'url' => $this->url,
            'maxPages' => $this->maxPages,
            'outputDir' => $this->outputDir,
            'label' => $this->label,
            'skipImages' => $this->skipImages,
            'startFromIndex' => $this->startFromIndex,
            'incompleteDirectories' => $this->incompleteDirectories,
            'emptyDirectoriesToReuse' => $this->emptyDirectoriesToReuse,
            'sourceType' => $this->sourceType,
        ];

        // Add optional fields if present
        if ($this->imageExceptions !== null) {
            $config['imageExceptions'] = $this->imageExceptions;
        }

        if ($this->dateSelector !== null) {
            $config['dateSelector'] = $this->dateSelector;
        }

        if ($this->urls !== null) {
            $config['urls'] = $this->urls;
            $config['isLocalFile'] = true;
        }

        if ($this->continueOffset !== null) {
            $config['continueOffset'] = $this->continueOffset;
        }

        return $config;
    }

    /**
     * Convert the configuration to a JSON string.
     *
     * Serializes the configuration to JSON format for passing to the Node.js
     * crawler process as a command-line argument.
     *
     * @return string JSON-encoded configuration
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
