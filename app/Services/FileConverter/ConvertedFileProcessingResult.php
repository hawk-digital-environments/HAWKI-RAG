<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

readonly class ConvertedFileProcessingResult
{
    private function __construct(
        public string $status,
        public ?array $failure = null,
    ) {
    }

    public static function processed(): self
    {
        return new self('processed');
    }

    public static function skipped(): self
    {
        return new self('skipped');
    }

    /**
     * @param array<string, mixed> $failure
     */
    public static function failed(array $failure): self
    {
        return new self('failed', $failure);
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
