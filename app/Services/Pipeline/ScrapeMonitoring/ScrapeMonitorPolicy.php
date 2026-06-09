<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class ScrapeMonitorPolicy
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    public function maxStatusReadFailures(): int
    {
        return max(1, (int) $this->config->get('communication.rabbitmq.pipeline_events.scrape_monitor_status_read_retries', 5));
    }

    public function maxMonitorAttempts(): int
    {
        return max(1, (int) $this->config->get('communication.rabbitmq.pipeline_events.scrape_monitor_max_attempts', 240));
    }

    public function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    public function carbonNow(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
