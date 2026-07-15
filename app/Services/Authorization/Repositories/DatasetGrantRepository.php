<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Services\Authorization\Values\AuthenticatedPrincipal;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class DatasetGrantRepository
{
    public function findActiveDatasetForQuery(
        AuthenticatedPrincipal $principal,
        string $datasetId,
    ): ?Dataset {
        return $this->activeDatasetsForQuery($principal)
            ->where('datasets.dataset_id', $datasetId)
            ->first();
    }

    /**
     * @return Collection<int, Dataset>
     */
    public function listActiveDatasetsForQuery(AuthenticatedPrincipal $principal): Collection
    {
        return $this->activeDatasetsForQuery($principal)
            ->select([
                'datasets.dataset_id',
                'datasets.name',
                'datasets.qdrant_collection',
                'datasets.neo4j_namespace',
                'datasets.embedding_provider',
                'datasets.embedding_model',
            ])
            ->orderBy('datasets.name')
            ->orderBy('datasets.dataset_id')
            ->get();
    }

    public function grantQueryAccess(
        Dataset $dataset,
        AuthenticatedPrincipal $principal,
    ): DatasetGrant {
        return DatasetGrant::query()->firstOrCreate([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => $principal->type,
            'principal_id' => $principal->id,
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);
    }

    /**
     * @return Builder<Dataset>
     */
    private function activeDatasetsForQuery(AuthenticatedPrincipal $principal): Builder
    {
        return Dataset::query()
            ->where('datasets.status', Dataset::STATUS_ACTIVE)
            ->whereExists(function ($query) use ($principal): void {
                $query->selectRaw('1')
                    ->from('dataset_grants')
                    ->whereColumn('dataset_grants.dataset_id', 'datasets.dataset_id')
                    ->where('dataset_grants.principal_type', $principal->type)
                    ->where('dataset_grants.principal_id', $principal->id)
                    ->where('dataset_grants.permission', DatasetGrant::PERMISSION_QUERY);
            });
    }
}
