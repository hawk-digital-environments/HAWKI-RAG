<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentOutputRepository
{
    public function __construct(
        private ClockInterface $clock = new Clock,
    ) {}

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

    public function unverifiedForDocument(string $documentId): Collection
    {
        return ManagedDocumentOutput::query()->where('document_id', $documentId)
            ->where('status', '!=', 'deleted')->orderBy('id')->get();
    }

    public function registerWriterTarget(ManagedDocument $document, IngestionSource $source, PipelineJob $job, array $target, array $scope, bool $reconciled = false): void
    {
        $existing = ManagedDocumentOutput::query()->where('document_id', $document->document_id)
            ->where('bridge_document_id', $target['doc_id'])->first();
        $namespace = ! empty($scope['graph']) ? ($scope['neo4j_namespace'] ?? null) : null;
        if ($existing && $existing->source_id === $source->source_id && $existing->qdrant_collection === $scope['collection']
            && ($existing->status !== 'deleted' || $reconciled) && $existing->neo4j_namespace === null && $namespace !== null) {
            // Effective requirements may expand on a retry; never erase earlier graph ownership.
            $existing->forceFill(['neo4j_namespace' => $namespace])->save();
        }
        if ($existing && ($existing->source_id !== $source->source_id || $existing->qdrant_collection !== $scope['collection']
            || $existing->neo4j_namespace !== $namespace || ($existing->status === 'deleted' && ! $reconciled))) {
            throw ManagedDeletionException::conflict('Recorded writer target scope conflicts with historical output metadata.');
        }
        if ($existing && $existing->status === 'deleted' && $reconciled) {
            // Local reconciliation reopens metadata for fresh physical verification.
            $existing->forceFill(['active' => true, 'status' => 'writing', 'deleted_at' => null])->save();
        }
        ManagedDocumentOutput::query()->firstOrCreate(['document_id' => $document->document_id, 'bridge_document_id' => $target['doc_id']], [
            'source_id' => $source->source_id, 'task_id' => $job->task_id, 'job_id' => $job->job_id,
            'qdrant_collection' => $scope['collection'], 'neo4j_namespace' => $namespace,
            'active' => true, 'status' => 'writing', 'metadata_json' => ['relative_path' => $target['relative_path'], 'writer_registered' => true],
        ]);
    }

    /**
     * @return list<string>
     */
    public function activeBridgeDocumentIdsForDataset(string $datasetId): array
    {
        return ManagedDocumentOutput::query()
            ->where('active', true)
            ->whereNull('deleted_at')
            ->whereHas('document', function ($query) use ($datasetId): void {
                $query->where('dataset_id', $datasetId)
                    ->whereNull('deleted_at');
            })
            ->whereNotNull('bridge_document_id')
            ->pluck('bridge_document_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $outputs
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
     * @param  Collection<int, ManagedDocumentOutput>  $outputs
     * @return Collection<int, ManagedDocumentOutput>
     */
    public function backfillScopes(Collection $outputs): Collection
    {
        if ($outputs->isEmpty()) {
            return $outputs;
        }

        foreach ($outputs as $output) {
            $updates = [];
            $source = IngestionSource::query()->where('source_id', $output->source_id)->first();
            $historical = $source?->metadata['managed_dispatch']['input']['ingestion'] ?? $source?->metadata['dataset'] ?? [];
            $fallbackCollection = $this->stringValue($historical['collection'] ?? $historical['qdrant_collection'] ?? null);
            $fallbackNamespace = ($historical['graph'] ?? true) ? $this->stringValue($historical['neo4j_namespace'] ?? null) : null;

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

        return $outputs;
    }

    /** Only frozen output rows may become inactive after verified cleanup. */
    public function deactivateSelected(ManagedDocument $document, array $targets, Carbon $deletedAt): void
    {
        foreach ($targets as $target) {
            $query = ManagedDocumentOutput::query()->where('document_id', $document->document_id)
                ->whereKey($target['output_id'])->where('bridge_document_id', $target['doc_id'])
                ->where('source_id', $target['source_id'])->where('qdrant_collection', $target['collection'])
                ->where('neo4j_namespace', $target['neo4j_namespace']);
            if (! $query->exists()) {
                if (! ManagedDocumentOutput::query()->where('document_id', $document->document_id)->whereKey($target['output_id'])->exists()) {
                    continue; // The frozen scope was verified and the metadata row is already absent.
                }
                throw ManagedDeletionException::conflict('Recorded deletion output scope has changed.');
            }
            $query->update(['active' => false, 'status' => 'deleted', 'deleted_at' => $deletedAt, 'updated_at' => $deletedAt]);
        }
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
