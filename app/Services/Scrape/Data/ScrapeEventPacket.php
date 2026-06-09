<?php

namespace App\Services\Scrape\Data;

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
class ScrapeEventPacket
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $event,
        public readonly array $data,
        public readonly string $timestamp,
    ) {}

    public function toArray(): array{
        return [
            'jobId' => $this->jobId,
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ];
    }
}
