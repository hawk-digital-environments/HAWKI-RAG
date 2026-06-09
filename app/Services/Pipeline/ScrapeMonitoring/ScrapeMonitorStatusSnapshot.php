<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

readonly class ScrapeMonitorStatusSnapshot
{
    public function __construct(
        public bool $success,
        public array $result,
        public array $data,
        public string $crawlerStatus,
        public string $datasetPath,
        public array $counts,
        public string $message,
        public mixed $httpStatus,
    ) {
    }

    public function completed(): bool
    {
        return $this->crawlerStatus === 'completed';
    }

    public function terminalFailure(): bool
    {
        return in_array($this->crawlerStatus, ['failed', 'cancelled', 'canceled', 'stopped'], true);
    }
}
