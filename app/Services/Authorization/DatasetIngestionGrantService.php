<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\Exceptions\DatasetQueryNotFoundException;
use App\Services\Authorization\Exceptions\GrantTokenRequiredException;
use App\Services\Authorization\Values\IngestionSelfGrant;
use App\Services\Dataset\DatasetRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class DatasetIngestionGrantService
{
    public function __construct(
        private DatasetRepository $datasets,
        private DatasetIngestionAuthorizationService $authorization,
        private DatasetGrantTokenService $grantTokens,
    ) {}

    /**
     * Grants the calling ingestion principal access to one active dataset.
     *
     * Strict by design: a first grant requires a valid one-time grant token
     * from the dataset's creation response — possession of that token is the
     * creator's proof of ownership, so a personal access token alone can
     * never widen its own reach. Callers who already hold the grant are
     * answered idempotently without a token (pure replay, no expansion).
     */
    public function grantSelf(User $user, string $datasetId, string|null $grantToken): IngestionSelfGrant
    {
        $dataset = $this->datasets->findByDatasetId(trim($datasetId));
        if (! $dataset instanceof Dataset || $dataset->status !== Dataset::STATUS_ACTIVE) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }

        if ($this->authorization->hasAccess($user, (string) $dataset->dataset_id)) {
            return new IngestionSelfGrant(
                $this->authorization->grantAccess($user, $dataset),
                replayed: true,
            );
        }

        if ($grantToken === null || $grantToken === '') {
            throw GrantTokenRequiredException::forSelfGrant();
        }

        return new IngestionSelfGrant(
            $this->grantTokens->redeem($dataset, $user, $grantToken),
            replayed: false,
        );
    }
}
