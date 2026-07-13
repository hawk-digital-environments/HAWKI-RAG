<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Services\Document\ManagedDocumentSyncService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentSyncService
{
    public function __construct(
        private ManagedDocumentSyncService $sync,
    ) {
    }

    public function sync(AssistantDocument $document): AssistantDocument
    {
        return $this->sync->sync($document);
    }
}
