<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeValidationResult;
use Illuminate\Filesystem\Filesystem;

/**
 * Service for validating crawler inputs and configurations.
 *
 * This service handles all validation logic for crawler operations,
 * including URL validation, parameter validation, and business rule
 * enforcement. It provides clear error messages for invalid inputs
 * and ensures data integrity before processing begins.
 */
class ScrapeValidationService
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * Validate a crawler job request.
     *
     * Performs comprehensive validation of all request parameters
     * and business rules. Returns true if validation passes, false
     * otherwise. Errors and warnings can be retrieved via getter methods.
     *
     * @param ScrapeJobRequest $request Job request to validate
     * @return bool True if validation passes
     */
    public function validate(ScrapeJobRequest $request): bool
    {
        return $this->validateResult($request)->valid();
    }

    public function validateResult(ScrapeJobRequest $request): ScrapeValidationResult
    {
        $errors = [];
        $warnings = [];

        // Validate URL
        if (blank($request->url)) {
            $errors[] = 'URL is required.';
        } elseif (!$this->isValidUrlOrFile($request->url)) {
            $errors[] = 'Invalid URL provided or file not found/readable.';
        }

        // Validate label
        if (blank($request->label)) {
            $errors[] = 'Label is required.';
        } elseif (!$this->isValidLabel($request->label)) {
            $errors[] = 'Label must contain only alphanumeric characters, dashes, and underscores.';
        }

        // Validate maxPages
        if ($request->maxPages < 0) {
            $errors[] = 'Maximum pages must be a non-negative integer.';
        }

        if ($request->maxPages > 10000) {
            $warnings[] = 'Maximum pages is very high (>10000). This may take a long time.';
        }

        // Validate output directory
        if (!blank($request->outputDir) && !$this->isValidDirectory($request->outputDir)) {
            $errors[] = 'Output directory does not exist or is not writable.';
        }

        // Validate image exceptions
        if ($request->imageExceptions !== null && !is_string($request->imageExceptions)) {
            $errors[] = 'Image exceptions must be a comma-separated string of CSS selectors.';
        }

        // Validate date selector
        if ($request->dateSelector !== null && blank($request->dateSelector)) {
            $warnings[] = 'Date selector is empty and will be ignored.';
        }

        // Validate concurrency settings
        if ($request->maxConcurrency <= 0) {
            $errors[] = 'Maximum concurrency must be a positive integer.';
        } elseif ($request->maxConcurrency > 20) {
            $warnings[] = 'Maximum concurrency is very high (>20). This may cause rate limiting issues.';
        }

        // Validate rate limiting
        if ($request->maxRpm <= 0) {
            $errors[] = 'Maximum RPM must be a positive integer.';
        }

        if ($request->requestDelay !== null && $request->requestDelay < 0) {
            $errors[] = 'Request delay must be a non-negative integer.';
        }

        return new ScrapeValidationResult($errors, $warnings);
    }

    /**
     * Check if a URL or file path is valid.
     *
     * Validates both remote URLs and local file paths.
     *
     * @param string $urlOrPath URL or file path to validate
     * @return bool
     */
    public function isValidUrlOrFile(string $urlOrPath): bool
    {
        // Check if it's a local file
        if ($this->files->exists($urlOrPath) && $this->files->isReadable($urlOrPath)) {
            return true;
        }

        // Check if it's a valid URL
        return filter_var($urlOrPath, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check if a label is valid.
     *
     * Labels must contain only alphanumeric characters, dashes, and underscores.
     *
     * @param string $label Label to validate
     * @return bool
     */
    public function isValidLabel(string $label): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $label) === 1;
    }

    /**
     * Check if a directory exists and is writable.
     *
     * @param string $directory Directory path to validate
     * @return bool
     */
    public function isValidDirectory(string $directory): bool
    {
        if ($this->files->exists($directory)) {
            if (!$this->files->isDirectory($directory)) {
                return false;
            }

            return $this->files->isWritable($directory);
        }

        $parent = dirname($directory);
        if ($parent === $directory || !$this->files->exists($parent) || !$this->files->isDirectory($parent)) {
            return false;
        }

        return $this->files->isWritable($parent);
    }

    /**
     * Validate that a local file contains valid URLs.
     *
     * @param string $filePath Path to the file
     * @return bool
     */
    public function isValidUrlListFile(string $filePath): bool
    {
        if (!$this->files->exists($filePath) || !$this->files->isReadable($filePath)) {
            return false;
        }

        $content = $this->files->get($filePath);
        $lines = explode("\n", $content);

        $validUrlCount = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (blank($line)) {
                continue;
            }

            if (filter_var($line, FILTER_VALIDATE_URL) !== false) {
                $validUrlCount++;
            }
        }

        return $validUrlCount > 0;
    }

}
