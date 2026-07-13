<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Services\Document\ManagedDocumentPayloadBuilder;
use Illuminate\Container\Attributes\Singleton;

/**
 * @deprecated Compatibility wrapper for legacy assistant services.
 */
#[Singleton]
readonly class AssistantDocumentPayloadBuilder
{
    public function __construct(
        private ManagedDocumentPayloadBuilder $documents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(AssistantDocument $document, bool $includeDetails = true): array
    {
        return $this->documents->build($document, $includeDetails);
    }
}
