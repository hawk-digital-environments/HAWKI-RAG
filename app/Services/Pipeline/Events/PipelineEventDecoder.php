<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineEventDecoder
{
    private PipelineEventNormalizer $normalizer;

    public function __construct(?PipelineEventNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new PipelineEventNormalizer(new PipelineEventTypeRegistry);
    }

    public function decode(string $body): array
    {
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($event)) {
            throw new \JsonException('Pipeline event payload must be a JSON object.');
        }

        return $this->normalizer->normalize((string) ($event['event_type'] ?? ''), $event);
    }
}
