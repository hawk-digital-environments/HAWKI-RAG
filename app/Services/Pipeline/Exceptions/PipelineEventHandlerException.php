<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

class PipelineEventHandlerException extends \RuntimeException implements PipelineExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function scrapeRequiresSourceUrl(): self
    {
        return new self('Scrape event requires source_url. Publish scrape.requested with a non-empty source_url.');
    }

    public static function scraperFailed(string $message): self
    {
        return new self("Scraper pipeline failed: {$message}");
    }

    public static function conversionRequiresExistingLocalPath(string $path): self
    {
        return new self("Conversion event requires an existing local_path file. Received [{$path}].");
    }

    public static function converterReturnedNoFiles(): self
    {
        return new self('Document converter returned no files. The converter must return at least one output file for file.discovered events.');
    }

    public static function ingestContentIsEmpty(string $path): self
    {
        return new self("Ingest content is empty for [{$path}]. Provide a markdown, text, or HTML file with non-empty content.");
    }

    public static function bridgeReturnedHttpFailure(int $status, string $body): self
    {
        return new self("Python RAG bridge returned HTTP {$status}: {$body}");
    }

    public static function scrapeMonitorFailure(string $message): self
    {
        return new self($message);
    }
}
