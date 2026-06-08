<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineEventDecoder
{
    public function decode(string $body): array
    {
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \JsonException('Pipeline event payload must be a JSON object.');
        }

        return PipelineEvent::normalize((string) ($event['event_type'] ?? ''), $event);
    }
}
