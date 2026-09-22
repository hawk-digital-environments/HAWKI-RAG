<?php

declare(strict_types=1);

namespace App\Services\Dataset\Repositories;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\TextIngestion\Values\TextIngestionMode;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DatasetGraphAvailabilityRepository
{
    public function hasReadyGraph(string $datasetId): bool
    {
        // Only active outputs count: replaced and deleted uploads can retain source history.
        if (ManagedDocumentOutput::query()
            ->where('active', true)
            ->where('status', 'indexed')
            ->whereNull('deleted_at')
            ->whereHas('document', function ($query) use ($datasetId): void {
                $query->where('dataset_id', $datasetId)
                    ->where('graph_enabled', true)
                    ->whereNull('deleted_at');
            })
            ->exists()) {
            return true;
        }

        foreach (IngestionSource::query()
            ->where('dataset_id', $datasetId)
            ->where('index_status', IngestionSource::STATUS_READY)
            ->select(['source_id', 'metadata'])
            ->cursor() as $source) {
            $metadata = $source->metadata ?? [];
            if (TextIngestionMode::isDirectText($metadata)) {
                continue;
            }

            $graph = data_get($metadata, 'request.metadata.graph') ?? ($metadata['graph'] ?? false);
            if (! filter_var($graph, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            if (! isset($metadata['upload'])) {
                return true;
            }

            // Upload outputs are projected lazily when document details are read.
            // A ready current source also counts before that first projection,
            // but retired sources and explicitly deactivated outputs do not.
            if (ManagedDocument::query()
                ->where('dataset_id', $datasetId)
                ->where('latest_source_id', $source->source_id)
                ->where('graph_enabled', true)
                ->whereNull('deleted_at')
                ->whereNotIn('status', [ManagedDocument::STATUS_DELETING, ManagedDocument::STATUS_DELETED, ManagedDocument::STATUS_FAILED])
                ->whereDoesntHave('outputs', fn ($query) => $query->where('source_id', $source->source_id))
                ->exists()) {
                return true;
            }
        }

        return false;
    }
}
