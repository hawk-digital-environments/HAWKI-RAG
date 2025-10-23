<?php

namespace App\Services\Crawler\Data;

class CrawlerResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly ?string $error = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return !$this->success;
    }
}
