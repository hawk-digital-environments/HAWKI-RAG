<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueuePayloadFactory
{
    /**
     * @param array<string, mixed>|null $queue
     * @return array<string, mixed>
     */
    public function make(string $name, ?array $queue): array
    {
        return [
            'name' => $name,
            'exists' => $queue !== null,
            'readyMessages' => (int) ($queue['messages_ready'] ?? 0),
            'unackedMessages' => (int) ($queue['messages_unacknowledged'] ?? 0),
            'messages' => (int) ($queue['messages'] ?? (($queue['messages_ready'] ?? 0) + ($queue['messages_unacknowledged'] ?? 0))),
            'consumers' => (int) ($queue['consumers'] ?? 0),
            'state' => $this->stringValue($queue['state'] ?? null) ?? ($queue ? 'unknown' : 'missing'),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
