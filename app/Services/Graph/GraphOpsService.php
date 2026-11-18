<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Dataset\DatasetBackend;
use App\Services\Dataset\LegacyDatasetStatePreflight;
use App\Services\Graph\Exceptions\Neo4jClientException;
use Illuminate\Contracts\Config\Repository;

final readonly class GraphOpsService
{
    public function __construct(private GraphOpsBackend $backend, private PythonGraphOpsClient $python,
        private Neo4jGraphExplorer $legacy, private DatasetQueryAuthorizationService $authorization,
        private Repository $config, private LegacyDatasetStatePreflight $legacyState, private Neo4jAdmin $admin,
        private DatasetBackend $datasets) {}

    public function read(User $user, string $action, array $data): array
    {
        abort_unless($this->backend->valid(), 503, 'Graph operations configuration is invalid.');
        $datasetAuthority = $this->datasets->selected();
        if ($this->backend->value() === 'python') {
            return $this->python->request('graph/'.$action, $data, $user);
        }
        $dataset = $data['dataset_id'];
        if ($datasetAuthority === DatasetBackend::PYTHON) {
            $scope = $this->python->request('graph/scope', ['dataset_id' => $dataset], $user);
            if (isset($scope['status'])) {
                return $scope;
            }
        } else {
            $authorized = $this->authorization->authorizeExactQuery($user, $dataset, includeGraph: true);
            $scope = ['dataset_id' => $authorized->datasetId, 'neo4j_namespace' => $authorized->neo4jNamespace];
        }
        abort_if(empty($scope['neo4j_namespace']), 409, 'Dataset graph scope is unavailable.');

        return match ($action) {
            'overview' => $this->legacy->overview((int) ($data['limit'] ?? 80), $scope),
            'search' => $this->legacy->searchEntities($data['q'], (int) ($data['limit'] ?? 12), $scope),
            'node' => $this->legacy->graphForNode($data['node_id'], 80, $scope),
            'expand' => $this->legacy->expand($data['node_id'], (int) ($data['depth'] ?? 1), (int) ($data['limit'] ?? 80), $scope),
            'semantic-search' => $this->legacy->semanticSearch($user, $dataset, $data['q'], (int) ($data['limit'] ?? 8),
                $this->config->get('config.dataset_backend') === 'python' ? $scope : null),
        };
    }

    public function clear(array $data): array
    {
        abort_unless($this->backend->valid(), 503, 'Graph operations configuration is invalid.');
        abort_unless($this->datasets->selected() === DatasetBackend::PYTHON, 503, 'Graph clear requires Python dataset admission authority.');
        // Existing frozen legacy state is not proof of writer drainage.
        $blocking = $this->legacyState->blockingArtifacts($data['dataset_id']);
        if ($blocking !== []) {
            return ['ok' => false, 'status' => 409, 'error' => 'legacy_graph_writers_unconfirmed', 'blocking' => $blocking];
        }

        if ($this->backend->value() === 'python') {
            return $this->python->request('graph/clear', $data, manager: true);
        }
        // Laravel executes only after Python has closed the existing durable
        // admission fence. No local lease or second writer registry exists.
        $admission = $this->python->request('graph/clear-admission', $data, manager: true);
        if (! ($admission['ok'] ?? false)) {
            return $admission;
        }
        try {
            $result = $this->admin->clearDataset($data['dataset_id'], $admission['neo4j_namespace']);
            if (($result['ok'] ?? null) !== true
                || ! is_int($result['deleted_nodes'] ?? null) || $result['deleted_nodes'] < 0
                || ! is_int($result['deleted_relationships'] ?? null) || $result['deleted_relationships'] < 0) {
                throw Neo4jClientException::invalidClearAcknowledgement();
            }
        } catch (\Throwable) {
            $this->python->request('graph/clear-receipt', ['dataset_id' => $data['dataset_id'],
                'operation_id' => $admission['operation_id'], 'success' => false], manager: true);

            return ['ok' => false, 'status' => 503, 'error' => 'graph_clear_reconciliation_required'];
        }
        $receipt = $this->python->request('graph/clear-receipt', ['dataset_id' => $data['dataset_id'],
            'operation_id' => $admission['operation_id'], 'success' => true,
            'deleted_nodes' => $result['deleted_nodes'], 'deleted_relationships' => $result['deleted_relationships']], manager: true);

        return ($receipt['ok'] ?? false) ? $result : $receipt;
    }

    public function authorizeSnapshot(User $user, string $datasetId): string
    {
        if ($this->datasets->selected() === DatasetBackend::PYTHON) {
            $scope = $this->python->request('graph/scope', ['dataset_id' => $datasetId], $user);
            abort_if(isset($scope['status']), (int) ($scope['status'] ?? 503), 'Dataset is unavailable.');

            return $scope['dataset_id'];
        } else {
            return $this->authorization->authorizeExactQuery($user, $datasetId)->datasetId;
        }
    }
}
