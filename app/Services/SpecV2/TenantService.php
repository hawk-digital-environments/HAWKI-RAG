<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Tenant;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\Repositories\TenantRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class TenantService
{
    public function __construct(
        private TenantRepository $tenants,
        private SpecIdentifierFactory $identifiers,
        private ApiActorScopeService $actors,
    ) {}

    public function list(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->tenants->paginate($this->actors->currentTenantFilters(), $perPage, $page);
    }

    public function show(string $tenantId): Tenant
    {
        $tenant = $this->tenants->findById($tenantId);
        if (! $tenant instanceof Tenant) {
            throw TenantNotFoundException::withId($tenantId);
        }

        if (! $this->actors->currentCanReadTenant($tenant)) {
            throw AccessDeniedException::forAction('read', 'tenant', $tenantId);
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): Tenant
    {
        $tenantId = $this->identifiers->identifier($input['id'] ?? null, 'tenant');
        $tenant = $this->tenants->create([
            'id' => $tenantId,
            'name' => $this->identifiers->displayName($tenantId, $input['name'] ?? null),
            'metadata_json' => $input['metadata'] ?? null,
        ]);

        $tenant->loadCount(['applications', 'groups', 'heaps']);

        return $tenant;
    }
}
