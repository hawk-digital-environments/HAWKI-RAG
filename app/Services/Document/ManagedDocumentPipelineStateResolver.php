<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Values\ManagedDocumentPipelineState;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentPipelineStateResolver
{
    public function __construct(
        private IngestionSourceRepository $sources,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $uploadPayload
     */
    public function resolve(array $uploadPayload): ManagedDocumentPipelineState
    {
        $sourceId = $this->stringValue($uploadPayload['source_id'] ?? $uploadPayload['sourceId'] ?? null);
        $source = $sourceId === null ? null : $this->sources->findBySourceId($sourceId);

        return ManagedDocumentPipelineState::fromUploadPayload(
            $uploadPayload,
            $source,
            Carbon::instance($this->clock->now()),
        );
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
