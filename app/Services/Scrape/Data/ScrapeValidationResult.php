<?php

declare(strict_types=1);

namespace App\Services\Scrape\Data;

readonly class ScrapeValidationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
