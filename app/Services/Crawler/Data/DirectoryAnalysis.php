<?php

namespace App\Services\Crawler\Data;

/**
 * Analysis results of crawl directory completeness.
 *
 * This immutable object contains the results of analyzing existing crawl
 * directories, categorizing them as complete or incomplete. Used to determine
 * how to proceed with crawl continuation and which directories need to be
 * cleaned up or re-crawled.
 *
 * @property-read array $complete Array of complete directory numbers
 * @property-read array $incomplete Array of incomplete directory numbers
 * @property-read int $lastComplete Highest complete directory number (0 if none)
 * @property-read array $incompleteUrls Map of incomplete directory numbers to their URLs
 */
class DirectoryAnalysis
{
    public function __construct(
        public readonly array $complete,
        public readonly array $incomplete,
        public readonly int $lastComplete,
        public readonly array $incompleteUrls,
    ) {}

    /**
     * Get the total number of existing directories (complete + incomplete).
     *
     * @return int Total count of all directories found
     */
    public function getTotalExisting(): int
    {
        return count($this->complete) + count($this->incomplete);
    }

    /**
     * Get the total number of complete directories.
     *
     * @return int Count of directories with valid, complete data
     */
    public function getTotalComplete(): int
    {
        return count($this->complete);
    }

    /**
     * Get the total number of incomplete directories.
     *
     * @return int Count of directories with missing or invalid data
     */
    public function getTotalIncomplete(): int
    {
        return count($this->incomplete);
    }

    /**
     * Check if there are any incomplete directories.
     *
     * @return bool True if one or more incomplete directories exist
     */
    public function hasIncomplete(): bool
    {
        return count($this->incomplete) > 0;
    }

    /**
     * Check if there are any complete directories.
     *
     * @return bool True if one or more complete directories exist
     */
    public function hasComplete(): bool
    {
        return count($this->complete) > 0;
    }
}
