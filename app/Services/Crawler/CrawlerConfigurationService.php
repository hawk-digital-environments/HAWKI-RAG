<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\DirectoryAnalysis;
use App\Services\Crawler\Data\UrlProcessingOptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrawlerConfigurationService
{
    public function __construct(
        private CrawlerProgressService $progressService,
        private CrawlerDirectoryService $directoryService,
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Process and validate the input URL
     */
    public function processUrl(string $url): UrlProcessingOptions
    {
        $isLocalFile = File::exists($url) && File::isReadable($url);

        if (!$isLocalFile && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL provided or file not found/readable.');
        }

        $sitemapUrls = [];
        $baseUrl = null;

        if ($isLocalFile) {
            $sitemapUrls = collect(explode("\n", File::get($url)))
                ->map(fn($line) => trim($line))
                ->filter(fn($line) => filled($line))
                ->filter(fn($line) => filter_var($line, FILTER_VALIDATE_URL) !== false)
                ->values()
                ->toArray();

            if (blank($sitemapUrls)) {
                throw new \InvalidArgumentException('The sitemap file does not contain any valid URLs.');
            }

            $parsedUrl = parse_url($sitemapUrls[0]);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        }

        // Determine source type
        $sourceType = 'direct';
        if ($isLocalFile) {
            $sourceType = 'local';
        } else {
            $lowerUrl = Str::lower($url);
            if (Str::contains($lowerUrl, ['sitemap', '.xml'])) {
                $sourceType = 'sitemap';
            }
        }

        return new UrlProcessingOptions(
            url: $url,
            isLocalFile: $isLocalFile,
            baseUrl: $baseUrl,
            sourceType: $sourceType,
            sitemapUrls: $sitemapUrls
        );
    }

    /**
     * Build crawler configuration
     */
    public function buildConfig(
        UrlProcessingOptions $urlOptions,
        string $outputDir,
        string $label,
        int $maxPages,
        bool $skipImages,
        int $startFromIndex,
        DirectoryAnalysis $directoryAnalysis,
        bool $shouldContinue,
        ?array $imageExceptions = null,
        ?string $dateSelector = null
    ): CrawlerConfig {
        $url = $urlOptions->isLocalFile ? $urlOptions->baseUrl : $urlOptions->url;

        return new CrawlerConfig(
            url: $url,
            maxPages: $maxPages,
            outputDir: $outputDir,
            label: $label,
            skipImages: $skipImages,
            startFromIndex: $startFromIndex,
            incompleteDirectories: $shouldContinue ? $directoryAnalysis->incompleteUrls : [],
            emptyDirectoriesToReuse: $shouldContinue
                ? array_diff($directoryAnalysis->incomplete, array_keys($directoryAnalysis->incompleteUrls))
                : [],
            sourceType: $urlOptions->sourceType,
            imageExceptions: $imageExceptions,
            dateSelector: $dateSelector
        );
    }

    /**
     * Apply URL continuation logic to configuration
     */
    public function applyUrlContinuation(
        CrawlerConfig $config,
        UrlProcessingOptions $urlOptions,
        bool $shouldContinue,
        int $startFromIndex,
        string $outputDir,
        string $label
    ): CrawlerConfig {
        if (!$shouldContinue || $startFromIndex <= 1) {
            // No continuation needed, but handle local file URLs
            if ($urlOptions->isLocal()) {
                $cleanUrls = $urlOptions->sitemapUrls;

                if ($config->maxPages > 0) {
                    $cleanUrls = array_slice($cleanUrls, 0, $config->maxPages);
                }

                return new CrawlerConfig(
                    url: $config->url,
                    maxPages: $config->maxPages,
                    outputDir: $config->outputDir,
                    label: $config->label,
                    skipImages: $config->skipImages,
                    startFromIndex: $config->startFromIndex,
                    incompleteDirectories: $config->incompleteDirectories,
                    emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
                    sourceType: $config->sourceType,
                    imageExceptions: $config->imageExceptions,
                    dateSelector: $config->dateSelector,
                    urls: $cleanUrls,
                    isLocalFile: true
                );
            }

            return $config;
        }

        // Calculate continuation offset
        $existingDirs = $this->directoryService->getExistingDirectories($outputDir, $label);
        $continueOffset = $this->progressService->calculateContinueOffset(
            $outputDir,
            $label,
            $urlOptions->sourceType,
            $urlOptions->sitemapUrls,
            $existingDirs
        );

        if ($urlOptions->isLocal()) {
            $cleanUrls = array_slice($urlOptions->sitemapUrls, $continueOffset);

            if ($config->maxPages > 0) {
                $cleanUrls = array_slice($cleanUrls, 0, $config->maxPages);
            }

            return new CrawlerConfig(
                url: $config->url,
                maxPages: $config->maxPages,
                outputDir: $config->outputDir,
                label: $config->label,
                skipImages: $config->skipImages,
                startFromIndex: $config->startFromIndex,
                incompleteDirectories: $config->incompleteDirectories,
                emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
                sourceType: $config->sourceType,
                imageExceptions: $config->imageExceptions,
                dateSelector: $config->dateSelector,
                urls: $cleanUrls,
                isLocalFile: true
            );
        }

        // For non-local sources, add continueOffset
        return new CrawlerConfig(
            url: $config->url,
            maxPages: $config->maxPages,
            outputDir: $config->outputDir,
            label: $config->label,
            skipImages: $config->skipImages,
            startFromIndex: $config->startFromIndex,
            incompleteDirectories: $config->incompleteDirectories,
            emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
            sourceType: $config->sourceType,
            imageExceptions: $config->imageExceptions,
            dateSelector: $config->dateSelector,
            urls: $config->urls,
            isLocalFile: $config->isLocalFile,
            continueOffset: $continueOffset
        );
    }
}
