<?php

declare(strict_types=1);

namespace App\Services\Scrape\Exceptions;

final class ScrapeProcessException extends \RuntimeException implements ScrapeExceptionInterface
{
    public static function notFound(string $jobId): self
    {
        return new self("Scrape process '{$jobId}' not found.");
    }
}
