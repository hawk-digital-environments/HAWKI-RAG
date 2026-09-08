<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\Exceptions\DatasetQueryNotFoundException;
use App\Services\Authorization\Repositories\DatasetGrantRepository;
use App\Services\Authorization\Values\AuthenticatedPrincipal;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class DatasetIngestionAuthorizationService
{
    public function __construct(
        private DatasetGrantRepository $grants,
    ) {}

    public function authorize(User $user, string $datasetId): void
    {
        $principal = $this->principalFor($user);
        $dataset = $this->grants->findDatasetForPermission(
            $principal,
            trim($datasetId),
            DatasetGrant::PERMISSION_INGEST,
        );

        if (! $dataset instanceof Dataset) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }
    }

    public function grantAccess(User $user, Dataset $dataset): DatasetGrant
    {
        return $this->grants->grant(
            $dataset,
            $this->principalFor($user),
            DatasetGrant::PERMISSION_INGEST,
        );
    }

    private function principalFor(User $user): AuthenticatedPrincipal
    {
        $principal = (bool) $user->isRemoved
            ? null
            : AuthenticatedPrincipal::tryFromUserIdentifier($user->getAuthIdentifier());

        if ($principal === null) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }

        return $principal;
    }
}
