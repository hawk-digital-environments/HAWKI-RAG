<?php

namespace App\Services\Crawler\Data;

class DirectoryAnalysis
{
    public function __construct(
        public readonly array $complete,
        public readonly array $incomplete,
        public readonly int $lastComplete,
        public readonly array $incompleteUrls,
    ) {}

    public function getTotalExisting(): int
    {
        return count($this->complete) + count($this->incomplete);
    }

    public function getTotalComplete(): int
    {
        return count($this->complete);
    }

    public function getTotalIncomplete(): int
    {
        return count($this->incomplete);
    }

    public function hasIncomplete(): bool
    {
        return count($this->incomplete) > 0;
    }

    public function hasComplete(): bool
    {
        return count($this->complete) > 0;
    }
}
