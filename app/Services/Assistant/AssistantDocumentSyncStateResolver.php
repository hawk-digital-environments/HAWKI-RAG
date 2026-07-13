<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Models\IngestionSource;
use App\Services\Assistant\Values\AssistantDocumentSyncState;
use App\Services\Document\ManagedDocumentSyncStateResolver;
use Illuminate\Container\Attributes\Singleton;

/**
 * @deprecated Compatibility adapter over the managed document sync-state resolver.
 */
#[Singleton]
readonly class AssistantDocumentSyncStateResolver
{
    public function __construct(
        private ManagedDocumentSyncStateResolver $resolver,
    ) {
    }

    public function resolve(AssistantDocument $document, IngestionSource $source): AssistantDocumentSyncState
    {
        $state = $this->resolver->resolve($document, $source);

        return new AssistantDocumentSyncState($state->attributes, $state->outputs);
    }
}
