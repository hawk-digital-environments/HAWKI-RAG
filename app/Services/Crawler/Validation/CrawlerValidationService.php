<?php

namespace App\Services\Crawler\Validation;

use App\Services\Crawler\Data\CrawlerJobRequest;
use Illuminate\Support\Facades\File;

/**
 * Service for validating crawler inputs and configurations.
 *
 * This service handles all validation logic for crawler operations,
 * including URL validation, parameter validation, and business rule
 * enforcement. It provides clear error messages for invalid inputs
 * and ensures data integrity before processing begins.
 */
class CrawlerValidationService
{
    /**
     * Validation errors accumulated during validation.
     *
     * @var array
     */
    private array $errors = [];

    /**
     * Validation warnings accumulated during validation.
     *
     * @var array
     */
    private array $warnings = [];

    /**
     * Validate a crawler job request.
     *
     * Performs comprehensive validation of all request parameters
     * and business rules. Returns true if validation passes, false
     * otherwise. Errors and warnings can be retrieved via getter methods.
     *
     * @param CrawlerJobRequest $request Job request to validate
     * @return bool True if validation passes
     */
    public function validate(CrawlerJobRequest $request): bool
    {
        $this->clearErrors();

        // Validate URL
        if (blank($request->url)) {
            $this->addError('URL is required.');
        } elseif (!$this->isValidUrlOrFile($request->url)) {
            $this->addError('Invalid URL provided or file not found/readable.');
        }

        // Validate label
        if (blank($request->label)) {
            $this->addError('Label is required.');
        } elseif (!$this->isValidLabel($request->label)) {
            $this->addError('Label must contain only alphanumeric characters, dashes, and underscores.');
        }

        // Validate maxPages
        if ($request->maxPages < 0) {
            $this->addError('Maximum pages must be a non-negative integer.');
        }

        if ($request->maxPages > 10000) {
            $this->addWarning('Maximum pages is very high (>10000). This may take a long time.');
        }

        // Validate output directory
        if (!blank($request->outputDir) && !$this->isValidDirectory($request->outputDir)) {
            $this->addError('Output directory does not exist or is not writable.');
        }

        // Validate image exceptions
        if ($request->imageExceptions !== null && !is_array($request->imageExceptions)) {
            $this->addError('Image exceptions must be an array of CSS selectors.');
        }

        // Validate date selector
        if ($request->dateSelector !== null && blank($request->dateSelector)) {
            $this->addWarning('Date selector is empty and will be ignored.');
        }

        // Validate concurrency settings
        if ($request->maxConcurrency <= 0) {
            $this->addError('Maximum concurrency must be a positive integer.');
        } elseif ($request->maxConcurrency > 20) {
            $this->addWarning('Maximum concurrency is very high (>20). This may cause rate limiting issues.');
        }

        // Validate rate limiting
        if ($request->maxRpm <= 0) {
            $this->addError('Maximum RPM must be a positive integer.');
        }

        if ($request->requestDelay !== null && $request->requestDelay < 0) {
            $this->addError('Request delay must be a non-negative integer.');
        }

        return !$this->hasErrors();
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
        if (File::exists($urlOrPath) && File::isReadable($urlOrPath)) {
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
        if (!File::exists($directory)) {
            return false;
        }

        if (!File::isDirectory($directory)) {
            return false;
        }

        return File::isWritable($directory);
    }

    /**
     * Validate that a local file contains valid URLs.
     *
     * @param string $filePath Path to the file
     * @return bool
     */
    public function isValidUrlListFile(string $filePath): bool
    {
        if (!File::exists($filePath) || !File::isReadable($filePath)) {
            return false;
        }

        $content = File::get($filePath);
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

    /**
     * Add a validation error.
     *
     * @param string $message Error message
     * @return void
     */
    private function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Add a validation warning.
     *
     * @param string $message Warning message
     * @return void
     */
    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Clear all errors and warnings.
     *
     * @return void
     */
    private function clearErrors(): void
    {
        $this->errors = [];
        $this->warnings = [];
    }

    /**
     * Get all validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all validation warnings.
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any validation errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Check if there are any validation warnings.
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * Get a formatted error message.
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        if (!$this->hasErrors()) {
            return '';
        }

        return 'Validation failed: ' . implode('; ', $this->errors);
    }
}
