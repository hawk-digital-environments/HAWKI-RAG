<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeValidationResult;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ScrapeValidationService
{
    public function __construct(
        private readonly ScrapeRequestValidator $requests,
        private readonly ScrapePathValidator $paths,
        private readonly ScrapeLabelValidator $labels,
    ) {
    }

    public function validate(ScrapeJobRequest $request): bool
    {
        return $this->validateResult($request)->valid();
    }

    public function validateResult(ScrapeJobRequest $request): ScrapeValidationResult
    {
        return $this->requests->validate($request);
    }

    public function isValidUrlOrFile(string $urlOrPath): bool
    {
        return $this->paths->isValidUrlOrFile($urlOrPath);
    }

    public function isValidLabel(string $label): bool
    {
        return $this->labels->isValid($label);
    }

    public function isValidDirectory(string $directory): bool
    {
        return $this->paths->isValidDirectory($directory);
    }

    public function isValidUrlListFile(string $filePath): bool
    {
        return $this->paths->isValidUrlListFile($filePath);
    }
}
