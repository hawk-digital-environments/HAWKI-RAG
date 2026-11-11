<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\User;
use App\Services\Authorization\DatasetIngestionGrantService;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Authorization\DatasetQueryGrantService;
use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use Illuminate\Container\Attributes\Singleton;

/**
 * The single dataset-management boundary used by the public BFF routes.
 *
 * In Laravel mode every operation delegates to the existing implementation,
 * so its envelopes, statuses and exceptions remain unchanged. In Python mode
 * every operation is forwarded to the Python authority and mapped back onto
 * the same legacy envelopes. No operation falls back from one authority to
 * the other.
 */
#[Singleton]
readonly class DatasetAuthority
{
    public function __construct(
        private DatasetBackend $backend,
        private DatasetService $datasets,
        private DatasetQueryGrantService $queryGrants,
        private DatasetIngestionGrantService $ingestGrants,
        private DatasetQueryAuthorizationService $queryAuthorization,
        private PythonDatasetClient $python,
        private LegacyDatasetStatePreflight $legacyState,
    ) {}

    /** @return array{status:int, payload:array<string, mixed>} */
    public function list(int $limit = 50): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'datasets' => $this->datasets->list($limit),
                ],
            ],
            DatasetBackend::PYTHON => $this->python->list($limit),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function show(string $datasetId): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => $this->legacyShow($datasetId),
            DatasetBackend::PYTHON => $this->python->show($datasetId),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function create(array $input): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => $this->legacyCreate($input),
            DatasetBackend::PYTHON => $this->python->create($input),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function delete(string $datasetId): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => $this->legacyDelete($datasetId),
            DatasetBackend::PYTHON => $this->pythonDelete($datasetId),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function grantQuerySelf(User $user, string $datasetId): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => $this->legacyGrantQuerySelf($user, $datasetId),
            DatasetBackend::PYTHON => $this->python->grantQuerySelf($user, $datasetId),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function grantIngestSelf(User $user, string $datasetId, ?string $grantToken): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => $this->legacyGrantIngestSelf($user, $datasetId, $grantToken),
            DatasetBackend::PYTHON => $this->python->grantIngestSelf($user, $datasetId, $grantToken),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function queryDatasets(User $user): array
    {
        return match ($this->backend->selected()) {
            DatasetBackend::LARAVEL => [
                'status' => 200,
                'payload' => [
                    'datasets' => $this->queryAuthorization->authorizedDatasets($user),
                ],
            ],
            DatasetBackend::PYTHON => $this->python->queryDatasets($user),
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function legacyShow(string $datasetId): array
    {
        $dataset = $this->datasets->show($datasetId);
        if ($dataset === null) {
            return $this->legacyNotFound($datasetId);
        }

        return ['status' => 200, 'payload' => ['success' => true, 'dataset' => $dataset]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function legacyCreate(array $input): array
    {
        $creation = $this->datasets->create($input);
        $payload = [
            'success' => true,
            'dataset_id' => $creation->dataset->dataset_id,
            'dataset' => $this->datasets->show($creation->dataset->dataset_id),
        ];
        if ($creation->grantToken !== null) {
            $payload['grant_token'] = $creation->grantToken;
        }

        return ['status' => $creation->created ? 201 : 200, 'payload' => $payload];
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function legacyDelete(string $datasetId): array
    {
        $result = $this->datasets->delete($datasetId);
        if ($result === null) {
            return $this->legacyNotFound($datasetId);
        }

        return [
            'status' => ($result['cleanup_pending'] ?? false) ? 202 : 200,
            'payload' => [
                'success' => true,
                'message' => ($result['cleanup_pending'] ?? false)
                    ? "Dataset {$datasetId} is hidden. Scoped cleanup will continue automatically in the background."
                    : "Dataset {$datasetId} cleanup is complete.",
                'cleanup' => $result,
            ],
        ];
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function legacyGrantQuerySelf(User $user, string $datasetId): array
    {
        $grant = $this->queryGrants->grantSelf($user, $datasetId);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'dataset_id' => (string) $grant->dataset_id,
                'query_access' => [
                    'granted' => true,
                    'permission' => (string) $grant->permission,
                ],
            ],
        ];
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function legacyGrantIngestSelf(
        User $user,
        string $datasetId,
        ?string $grantToken,
    ): array {
        $grant = $this->ingestGrants->grantSelf($user, $datasetId, $grantToken);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'dataset_id' => (string) $grant->grant->dataset_id,
                'ingest_access' => [
                    'granted' => true,
                    'permission' => (string) $grant->grant->permission,
                ],
                'replayed' => $grant->replayed,
            ],
        ];
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function pythonDelete(string $datasetId): array
    {
        $blocking = $this->legacyState->blockingArtifacts($datasetId);
        if ($blocking !== []) {
            return [
                'status' => 409,
                'payload' => [
                    'success' => false,
                    'error' => 'legacy_dataset_state_present',
                    'message' => "Dataset {$datasetId} still has Laravel-owned state and cannot be deleted through the Python authority.",
                    'blocking' => $blocking,
                ],
            ];
        }

        return $this->python->delete($datasetId);
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function legacyNotFound(string $datasetId): array
    {
        return [
            'status' => 404,
            'payload' => [
                'success' => false,
                'message' => "Dataset {$datasetId} was not found.",
            ],
        ];
    }
}
