<?php

declare(strict_types=1);

namespace App\Services\Scrape\Exceptions;

final class ScrapeInputException extends \InvalidArgumentException implements ScrapeExceptionInterface
{
    public static function invalidImageExceptions(): self
    {
        return new self('Image exceptions must be a string or an array of CSS selectors.');
    }
}
