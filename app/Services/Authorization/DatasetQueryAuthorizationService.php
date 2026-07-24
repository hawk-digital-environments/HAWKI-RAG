<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\Exceptions\DatasetNotReadyException;
use App\Services\Authorization\Exceptions\DatasetQueryNotFoundException;
use App\Services\Authorization\Repositories\DatasetGrantRepository;
use App\Services\Authorization\Values\AuthenticatedPrincipal;
use App\Services\Authorization\Values\AuthorizedDatasetScope;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DatasetQueryAuthorizationService
{
    public function __construct(
        private DatasetGrantRepository $grants,
        #[Config('config.query_auth.all_datasets_by_default')]
        private bool $allDatasetsByDefault,
    ) {}

    public function authorize(User $user, string $datasetId): AuthorizedDatasetScope
    {
        $principal = $this->principalFor($user);
        $dataset = $this->grants->findActiveDatasetForQuery(
            $principal,
            trim($datasetId),
            $this->canQueryAllDatasets(),
        );

        if (! $dataset instanceof Dataset) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }

        if (! $this->isReadyForQuery($dataset)) {
            throw DatasetNotReadyException::storageTargetsAreMissing();
        }

        return AuthorizedDatasetScope::fromStorageTargets(
            datasetId: (string) $dataset->dataset_id,
            qdrantCollection: trim((string) $dataset->qdrant_collection),
            neo4jNamespace: trim((string) $dataset->neo4j_namespace),
            embeddingProvider: trim((string) $dataset->embedding_provider),
            embeddingModel: trim((string) $dataset->embedding_model),
        );
    }

    /**
     * Check the dataset grant only; storage readiness remains an operational check in authorize().
     */
    public function canQuery(User $user, string $datasetId): bool
    {
        $principal = $this->tryPrincipalFor($user);

        if ($principal === null) {
            return false;
        }

        return $this->grants->findActiveDatasetForQuery(
            $principal,
            trim($datasetId),
            $this->canQueryAllDatasets(),
        ) instanceof Dataset;
    }

    /**
     * @return list<array{dataset_id:string,name:string}>
     */
    public function authorizedDatasets(User $user): array
    {
        $principal = $this->tryPrincipalFor($user);
        if ($principal === null) {
            return [];
        }

        return $this->grants->listActiveDatasetsForQuery($principal, $this->canQueryAllDatasets())
            ->filter(fn (Dataset $dataset): bool => $this->isReadyForQuery($dataset))
            ->map(static fn (Dataset $dataset): array => [
                'dataset_id' => (string) $dataset->dataset_id,
                'name' => (string) $dataset->name,
            ])
            ->values()
            ->all();
    }

    public function grantQueryAccess(User $user, Dataset $dataset): DatasetGrant
    {
        return $this->grants->grantQueryAccess($dataset, $this->principalFor($user));
    }

    private function principalFor(User $user): AuthenticatedPrincipal
    {
        $principal = $this->tryPrincipalFor($user);

        if ($principal === null) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }

        return $principal;
    }

    private function tryPrincipalFor(User $user): ?AuthenticatedPrincipal
    {
        if ((bool) $user->isRemoved) {
            return null;
        }

        return AuthenticatedPrincipal::tryFromUserIdentifier($user->getAuthIdentifier());
    }

    public function isReadyForQuery(Dataset $dataset): bool
    {
        return trim((string) $dataset->qdrant_collection) !== ''
            && trim((string) $dataset->neo4j_namespace) !== ''
            && trim((string) $dataset->embedding_provider) !== ''
            && trim((string) $dataset->embedding_model) !== '';
    }

    private function canQueryAllDatasets(): bool
    {
        return $this->allDatasetsByDefault;
    }
}
