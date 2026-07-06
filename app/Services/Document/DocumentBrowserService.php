<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Services\Authorization\ApiActorScopeService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentBrowserService
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentPayloadBuilder $payloads,
        private ApiActorScopeService $actors,
    ) {}

    public function list(int $limit = 100, array $filters = [], ?string $requestedUserIdentifier = null): array
    {
        $limit = max(1, min(250, $limit));
        $filters = [
            ...$filters,
            ...$this->actors->currentDocumentFilters($requestedUserIdentifier),
        ];

        return $this->documents->list($filters, $limit)
            ->map(fn (Document $document): array => $this->payloads->payload($document, includeDetails: false))
            ->all();
    }

    public function show(string $documentId, ?string $requestedUserIdentifier = null): ?array
    {
        if (! $this->actors->currentCanReadDocument($documentId, $requestedUserIdentifier)) {
            return null;
        }

        $document = $this->documents->findById($documentId);

        return $document ? $this->payloads->payload($document, includeDetails: true) : null;
    }
}
