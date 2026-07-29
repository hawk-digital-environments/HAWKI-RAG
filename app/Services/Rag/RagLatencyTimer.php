<?php

declare(strict_types=1);

namespace App\Services\Rag;

use DateTimeInterface;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class RagLatencyTimer
{
    public function __construct(private ClockInterface $clock = new Clock())
    {
    }

    public function start(): DateTimeInterface
    {
        return $this->clock->now();
    }

    public function elapsedMs(DateTimeInterface $startedAt): int
    {
        return max(0, (int) floor(($this->micros($this->clock->now()) - $this->micros($startedAt)) / 1000));
    }

    private function micros(DateTimeInterface $time): int
    {
        return ((int) $time->format('U') * 1000000) + (int) $time->format('u');
    }
}
