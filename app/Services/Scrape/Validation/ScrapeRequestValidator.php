<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeValidationResult;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeRequestValidator
{
    public function __construct(
        private ScrapePathValidator $paths,
        private ScrapeLabelValidator $labels,
    ) {
    }

    public function validate(ScrapeJobRequest $request): ScrapeValidationResult
    {
        $errors = [];
        $warnings = [];

        if ($this->blank($request->url)) {
            $errors[] = 'URL is required.';
        } elseif (! $this->paths->isValidUrlOrFile($request->url)) {
            $errors[] = 'Invalid URL provided or file not found/readable.';
        }

        if ($this->blank($request->label)) {
            $errors[] = 'Label is required.';
        } elseif (! $this->labels->isValid($request->label)) {
            $errors[] = 'Label must contain only alphanumeric characters, dashes, and underscores.';
        }

        if ($request->maxPages < 0) {
            $errors[] = 'Maximum pages must be a non-negative integer.';
        }

        if ($request->maxPages > 10000) {
            $warnings[] = 'Maximum pages is very high (>10000). This may take a long time.';
        }

        if (! $this->blank($request->outputDir) && ! $this->paths->isValidDirectory($request->outputDir)) {
            $errors[] = 'Output directory does not exist or is not writable.';
        }

        if ($request->imageExceptions !== null && ! is_string($request->imageExceptions)) {
            $errors[] = 'Image exceptions must be a comma-separated string of CSS selectors.';
        }

        if ($request->dateSelector !== null && $this->blank($request->dateSelector)) {
            $warnings[] = 'Date selector is empty and will be ignored.';
        }

        if ($request->maxConcurrency <= 0) {
            $errors[] = 'Maximum concurrency must be a positive integer.';
        } elseif ($request->maxConcurrency > 20) {
            $warnings[] = 'Maximum concurrency is very high (>20). This may cause rate limiting issues.';
        }

        if ($request->maxRpm <= 0) {
            $errors[] = 'Maximum RPM must be a positive integer.';
        }

        if ($request->requestDelay !== null && $request->requestDelay < 0) {
            $errors[] = 'Request delay must be a non-negative integer.';
        }

        return new ScrapeValidationResult($errors, $warnings);
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
