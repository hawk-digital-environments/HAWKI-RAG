<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class GraphSnapshotService
{
    public function __construct(private GraphSnapshotStore $store, private GraphOpsService $ops,
        private Repository $config) {}

    public function save(User $user, array $payload): array
    {
        $dataset = $this->ops->authorizeSnapshot($user, $payload['dataset_id']);

        return $this->store->save(array_merge($payload, [
            'dataset_id' => $dataset, 'tenant_id' => $this->tenant(),
        ]));
    }

    public function list(User $user): array
    {
        $result = $this->store->list();
        if (! ($result['ok'] ?? false)) {
            return $result;
        }
        $visible = [];
        foreach ($result['snapshots'] as $snapshot) {
            try {
                $this->authorizeStored($user, $snapshot);
                $visible[] = $snapshot;
            } catch (NotFoundHttpException) {
                // Conceal all metadata for unowned, foreign or ungranted scenes.
            }
        }
        $result['snapshots'] = $visible;

        return $result;
    }

    public function load(User $user, string $id): array
    {
        $result = $this->store->load($id);
        abort_unless(($result['ok'] ?? false) && is_array($result['snapshot'] ?? null), 404, 'Snapshot not found.');
        $this->authorizeStored($user, $result['snapshot']);

        return $result;
    }

    public function delete(User $user, string $id): array
    {
        $this->load($user, $id);

        return $this->store->delete($id);
    }

    private function authorizeStored(User $user, array $snapshot): void
    {
        // Unowned legacy snapshots remain unavailable until ownership is assigned
        // through a trusted process. Browser flags never establish ownership.
        abort_unless(is_string($snapshot['dataset_id'] ?? null) && trim($snapshot['dataset_id']) !== ''
            && ($snapshot['tenant_id'] ?? null) === $this->tenant(), 404, 'Snapshot not found.');
        $this->ops->authorizeSnapshot($user, $snapshot['dataset_id']);
    }

    private function tenant(): string
    {
        // The authenticated BFF uses this same deployment-bound tenant claim.
        $tenant = $this->config->get('config.query_bff.tenant');
        abort_unless(is_string($tenant) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $tenant), 503, 'Snapshot tenant is unavailable.');

        return $tenant;
    }
}
