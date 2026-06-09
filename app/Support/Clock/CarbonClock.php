<?php

declare(strict_types=1);

namespace App\Support\Clock;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

readonly class CarbonClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return CarbonImmutable::now()->toDateTimeImmutable();
    }
}
