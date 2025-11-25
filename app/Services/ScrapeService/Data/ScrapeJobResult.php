<?php

namespace App\Services\ScrapeService\Data;

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
 * @property-read array $metadata Additional metadata about the job
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
        public readonly array $metadata = [],
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

        if ($context->config) {
            $statistics['maxPages'] = $context->config->maxPages;
//            $statistics['startFromIndex'] = $context->config->startFromIndex;
        }

        // Extract artifacts
        if ($context->config) {
            $artifacts['outputDir'] = $context->config->outputDir;
            $artifacts['label'] = $context->config->label;
            $artifacts['crawlDir'] = "{$context->config->outputDir}/{$context->config->label}";
        }

        // Calculate duration if available
        $metadata = $context->metadata;
        if (isset($metadata['startTime']) && isset($metadata['endTime'])) {
            $metadata['duration'] = $metadata['endTime']->diffInSeconds($metadata['startTime']);
        }

        return new static(
            success: !$context->hasErrors(),
            jobId: $context->jobId,
            statistics: $statistics,
            errors: $context->getErrors(),
            warnings: $context->getWarnings(),
            artifacts: $artifacts,
            metadata: $metadata,
        );
    }

    /**
     * Create a success result.
     *
     * @param string $jobId Job identifier
     * @param array $statistics Statistics data
     * @param array $artifacts Artifact paths
     * @param array $metadata Additional metadata
     * @return static
     */
    public static function success(
        string $jobId,
        array $statistics = [],
        array $artifacts = [],
        array $metadata = []
    ): static {
        return new static(
            success: true,
            jobId: $jobId,
            statistics: $statistics,
            artifacts: $artifacts,
            metadata: $metadata,
        );
    }

    /**
     * Create a failure result.
     *
     * @param string $jobId Job identifier
     * @param array $errors Error messages
     * @param array $statistics Statistics data
     * @param array $metadata Additional metadata
     * @return static
     */
    public static function failure(
        string $jobId,
        array $errors,
        array $statistics = [],
        array $metadata = []
    ): static {
        return new static(
            success: false,
            jobId: $jobId,
            statistics: $statistics,
            errors: $errors,
            metadata: $metadata,
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
            'metadata' => $this->metadata,
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
