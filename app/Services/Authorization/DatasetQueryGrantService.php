<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Authorization\Exceptions\DatasetNotReadyException;
use App\Services\Authorization\Exceptions\DatasetQueryNotFoundException;
use App\Services\Dataset\DatasetRepository;
use App\Services\Dataset\DatasetVectorStatsService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DatasetQueryGrantService
{
    public function __construct(
        private DatasetRepository $datasets,
        private DatasetVectorStatsService $vectorStats,
        private DatasetQueryAuthorizationService $authorization,
    ) {}

    /**
     * Grant the current query principal access to one admin-selected dataset.
     *
     * Existing grants remain idempotent even during a temporary Qdrant outage.
     * A first grant is fail-closed until the pipeline and physical vector store
     * both prove that the dataset is ready for retrieval.
     */
    public function grantSelf(User $user, string $datasetId): DatasetGrant
    {
        $dataset = $this->datasets->findByDatasetId(trim($datasetId));
        if (! $dataset instanceof Dataset || $dataset->status !== Dataset::STATUS_ACTIVE) {
            throw DatasetQueryNotFoundException::requestedDatasetIsUnavailable();
        }

        if ($this->authorization->canQuery($user, (string) $dataset->dataset_id)) {
            return $this->authorization->grantQueryAccess($user, $dataset);
        }

        $lastIngestion = $this->datasets->lastTerminalIngestionJob($dataset);
        if (
            ! $this->authorization->isReadyForQuery($dataset)
            || $lastIngestion?->status !== PipelineJob::STATUS_COMPLETED
        ) {
            throw DatasetNotReadyException::storageTargetsAreMissing();
        }

        $stats = $this->vectorStats->stats($dataset);
        if (($stats['ok'] ?? false) !== true || (int) ($stats['points'] ?? 0) < 1) {
            throw DatasetNotReadyException::storageTargetsAreMissing();
        }

        return $this->authorization->grantQueryAccess($user, $dataset);
    }
}
