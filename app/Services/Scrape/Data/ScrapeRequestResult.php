<?php

namespace App\Services\Scrape\Data;

use App\Services\Scrape\Pipeline\ScrapeContextBuilder;

/**
 * Result data transfer object returned from the crawler pipeline.
 *
 * @property-read bool $success Whether the job completed successfully
 * @property-read string $jobId Unique identifier for this job
 * @property-read string $stage Latest context stage
 * @property-read array $errors Errors encountered during execution
 * @property-read array $warnings Warnings generated during execution
 */
class ScrapeRequestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $jobId,
        public readonly string $stage,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}


    /**
     * Create a success result.
     *
     * @param string $jobId Job identifier
     * @param string $stage
     * @return static
     */
    public static function success(
        string $jobId,
        string $stage,
    ): static {
        return new static(
            success: true,
            jobId: $jobId,
            stage: $stage,
        );
    }

    /**
     * Create a failure result.
     *
     * @param string $jobId Job identifier
     * @param string $stage
     * @param array $errors Error messages
     * @param array $warnings Warning messages
     * @return static
     */
    public static function failure(
        string $jobId,
        string $stage,
        array $errors = [],
        array $warnings = [],
    ): static {
        return new static(
            success: false,
            jobId: $jobId,
            stage: $stage,
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'jobId' => $this->jobId,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

}
