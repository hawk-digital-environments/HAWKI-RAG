<?php

namespace App\Services\Crawler\Data;

/**
 * Data object representing the result of a crawler operation.
 *
 * This immutable object encapsulates the outcome of any crawler operation,
 * including success status, output messages, and error information. Used as
 * a standardized return type throughout the crawler system to communicate
 * operation results.
 *
 * @property-read bool $success Whether the operation completed successfully
 * @property-read string $output Output messages or data from the operation
 * @property-read string|null $error Error message if the operation failed (null on success)
 */
class CrawlerResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly ?string $error = null,
    ) {}

    /**
     * Check if the crawler operation was successful.
     *
     * @return bool True if the operation completed without errors
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Check if the crawler operation failed.
     *
     * @return bool True if the operation encountered an error
     */
    public function isFailed(): bool
    {
        return !$this->success;
    }
}
