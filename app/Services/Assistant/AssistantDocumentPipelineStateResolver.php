<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Assistant\Values\AssistantDocumentPipelineState;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentPipelineStateResolver
{
    public function __construct(
        private IngestionSourceRepository $sources,
    ) {
    }

    /**
     * @param array<string, mixed> $uploadPayload
     */
    public function resolve(array $uploadPayload): AssistantDocumentPipelineState
    {
        $sourceId = $this->stringValue($uploadPayload['sourceId'] ?? null);
        $source = $sourceId === null ? null : $this->sources->findBySourceId($sourceId);

        return AssistantDocumentPipelineState::fromUploadPayload($uploadPayload, $source);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
