<?php

namespace App\Services\ScrapeService\Data;

use App\Services\ScrapeService\Pipeline\ScrapeContextBuilder;

/**
 * Result data transfer object returned from the crawler pipeline.
 *
 * This immutable object encapsulates the complete outcome of a crawler job,
 * including success status, statistics, errors, and artifacts. It provides
 * a consistent interface for consuming crawler results across different
 * entry points (commands, API, queues).
 *
 * @property-read bool $success Whether the job completed successfully
 * @property-read string $jobId Unique identifier for this job
 * @property-read array $statistics Collected statistics about the crawl
 * @property-read array $errors Errors encountered during execution
 * @property-read array $warnings Warnings generated during execution
 * @property-read array $artifacts Paths to generated files and data
 */
class ScrapeJobResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $jobId,
        public readonly array $statistics = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $artifacts = [],
    ) {}

    /**
     * Create a result from a crawler context.
     *
     * Extracts relevant information from the context to build the result.
     *
     * @param ScrapeContext $context Pipeline context
     * @return static
     */
    public static function fromContext(ScrapeContext $context): static
    {
        $statistics = [];
        $artifacts = [];

        // Extract statistics from context
//        if ($context->analysis) {
//            $statistics['existingDirectories'] = $context->analysis->getTotalExisting();
//            $statistics['completeDirectories'] = $context->analysis->getTotalComplete();
//            $statistics['incompleteDirectories'] = $context->analysis->getTotalIncomplete();
//        }

        if ($context->request) {
            $statistics['maxPages'] = $context->request->maxPages;
//            $statistics['startFromIndex'] = $context->config->startFromIndex;
        }

        // Extract artifacts
        if ($context->request) {
            $artifacts['outputDir'] = $context->request->outputDir;
            $artifacts['label'] = $context->request->label;
            $artifacts['crawlDir'] = "{$context->request->outputDir}/{$context->request->label}";
        }

        return new static(
            success: !$context->hasErrors(),
            jobId: $context->jobId,
            statistics: $statistics,
            errors: $context->getErrors(),
            warnings: $context->getWarnings(),
            artifacts: $artifacts,
        );
    }

    /**
     * Create a success result.
     *
     * @param string $jobId Job identifier
     * @param array $statistics Statistics data
     * @param array $artifacts Artifact paths
     * @return static
     */
    public static function success(
        string $jobId,
        array $statistics = [],
        array $artifacts = []
    ): static {
        return new static(
            success: true,
            jobId: $jobId,
            statistics: $statistics,
            artifacts: $artifacts,
        );
    }

    /**
     * Create a failure result.
     *
     * @param string $jobId Job identifier
     * @param array $errors Error messages
     * @param array $statistics Statistics data
     * @return static
     */
    public static function failure(
        string $jobId,
        array $errors,
        array $statistics = []
    ): static {
        return new static(
            success: false,
            jobId: $jobId,
            statistics: $statistics,
            errors: $errors,
        );
    }

    /**
     * Check if the job completed successfully.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Check if the job failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return !$this->success;
    }

    /**
     * Get a summary message for the result.
     *
     * @return string
     */
    public function getSummary(): string
    {
        if ($this->isSuccessful()) {
            $pageCount = $this->statistics['completeDirectories'] ?? 0;
            return "Crawl completed successfully. Processed {$pageCount} pages.";
        }

        $errorCount = count($this->errors);
        return "Crawl failed with {$errorCount} error(s).";
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
            'statistics' => $this->statistics,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'artifacts' => $this->artifacts,
        ];
    }

    /**
     * Convert to JSON string.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
