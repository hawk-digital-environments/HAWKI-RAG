<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\Document\Values\ManagedDocumentId;
use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentOutputRepository
{
    public function __construct(
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return Collection<int, ManagedDocumentOutput>
     */
    public function activeForDocument(ManagedDocumentId|string $managedDocumentId): Collection
    {
        return ManagedDocumentOutput::query()
            ->where('document_id', $this->managedDocumentIdValue($managedDocumentId))
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<int, array<string, mixed>> $outputs
     * @return Collection<int, ManagedDocumentOutput>
     */
    public function syncActiveOutputs(ManagedDocument $document, array $outputs): Collection
    {
        $bridgeDocumentIds = [];
        $managedDocumentId = $document->documentId()->value;

        foreach ($outputs as $output) {
            $bridgeDocumentId = (string) ($output['bridge_document_id'] ?? '');
            if ($bridgeDocumentId === '') {
                continue;
            }

            $bridgeDocumentIds[] = $bridgeDocumentId;

            ManagedDocumentOutput::query()->updateOrCreate(
                [
                    'document_id' => $managedDocumentId,
                    'bridge_document_id' => $bridgeDocumentId,
                ],
                [
                    'qdrant_collection' => $output['qdrant_collection'] ?? '',
                    'neo4j_namespace' => $output['neo4j_namespace'] ?? null,
                    'source_id' => $output['source_id'] ?? null,
                    'task_id' => $output['task_id'] ?? null,
                    'job_id' => $output['job_id'] ?? null,
                    'content_hash' => $output['content_hash'] ?? null,
                    'chunk_count' => (int) ($output['chunk_count'] ?? 0),
                    'status' => $output['status'] ?? 'indexed',
                    'active' => true,
                    'indexed_at' => $output['indexed_at'] ?? null,
                    'deleted_at' => null,
                    'metadata_json' => $output['metadata_json'] ?? null,
                ],
            );
        }

        if ($bridgeDocumentIds !== []) {
            $deletedAt = $this->now();

            ManagedDocumentOutput::query()
                ->where('document_id', $managedDocumentId)
                ->where('active', true)
                ->whereNotIn('bridge_document_id', $bridgeDocumentIds)
                ->update([
                    'active' => false,
                    'deleted_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);
        }

        return $this->activeForDocument($document->documentId());
    }

    /**
     * @return Collection<int, ManagedDocumentOutput>
     */
    public function deactivateActiveOutputs(ManagedDocument $document, Carbon $deletedAt): Collection
    {
        $outputs = $this->activeForDocument($document->documentId());

        ManagedDocumentOutput::query()
            ->where('document_id', $document->documentId()->value)
            ->where('active', true)
            ->update([
                'active' => false,
                'status' => 'deleted',
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ]);

        return $outputs;
    }

    /**
     * @param Collection<int, ManagedDocumentOutput> $outputs
     * @return Collection<int, ManagedDocumentOutput>
     */
    public function backfillScopes(ManagedDocument $document, Collection $outputs): Collection
    {
        if ($outputs->isEmpty()) {
            return $outputs;
        }

        $dataset = Dataset::query()
            ->where('dataset_id', $document->dataset_id)
            ->first();

        $fallbackCollection = $this->stringValue($dataset?->qdrant_collection);
        $fallbackNamespace = $this->stringValue($dataset?->neo4j_namespace);

        foreach ($outputs as $output) {
            $updates = [];

            if ($this->stringValue($output->qdrant_collection) === null && $fallbackCollection !== null) {
                $updates['qdrant_collection'] = $fallbackCollection;
            }

            if ($this->stringValue($output->neo4j_namespace) === null && $fallbackNamespace !== null) {
                $updates['neo4j_namespace'] = $fallbackNamespace;
            }

            if ($updates !== []) {
                $output->forceFill($updates)->save();
            }
        }

        return $this->activeForDocument($document->documentId());
    }

    private function now(): Carbon
    {
        return Carbon::instance($this->clock->now());
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function managedDocumentIdValue(ManagedDocumentId|string $managedDocumentId): string
    {
        return ($managedDocumentId instanceof ManagedDocumentId
            ? $managedDocumentId
            : ManagedDocumentId::fromString($managedDocumentId))
            ->value;
    }
}
