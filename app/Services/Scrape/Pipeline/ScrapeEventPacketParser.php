<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Scrape\Data\ScrapeEventPacket;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeEventPacketParser
{
    public function isValid(array $data): bool
    {
        return isset($data['job_id']) &&
            isset($data['event']) &&
            isset($data['data']) &&
            isset($data['timestamp']) &&
            is_string($data['job_id']) &&
            is_string($data['event']) &&
            is_array($data['data']) &&
            is_string($data['timestamp']);
    }

    public function fromArray(array $data): ScrapeEventPacket
    {
        return new ScrapeEventPacket(
            jobId: $data['job_id'],
            event: $data['event'],
            data: $data['data'],
            timestamp: $data['timestamp'],
        );
    }
}
