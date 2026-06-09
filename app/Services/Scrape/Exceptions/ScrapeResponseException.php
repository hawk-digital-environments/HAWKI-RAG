<?php

declare(strict_types=1);

namespace App\Services\Scrape\Exceptions;

final class ScrapeResponseException extends \RuntimeException implements ScrapeExceptionInterface
{
    public static function expectedJsonObject(string $source): self
    {
        return new self("Expected {$source} JSON response to be an object.");
    }
}
