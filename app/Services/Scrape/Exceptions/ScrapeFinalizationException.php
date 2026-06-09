<?php

declare(strict_types=1);

namespace App\Services\Scrape\Exceptions;

class ScrapeFinalizationException extends \RuntimeException implements ScrapeExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidScrapedElement(string $urlHash, array $errors): self
    {
        return new self("Invalid scraped element {$urlHash}: ".implode('; ', $errors));
    }

    public static function missingPageUrl(string $urlHash): self
    {
        return new self("page_url is missing or empty in disk data for url_hash: {$urlHash}");
    }
}
