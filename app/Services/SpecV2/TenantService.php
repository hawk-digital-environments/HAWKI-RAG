<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Tenant;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\Payloads\PaginationPayloadBuilder;
use App\Services\SpecV2\Payloads\TenantPayloadBuilder;
use App\Services\SpecV2\Repositories\TenantRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class TenantService
{
    public function __construct(
        private TenantRepository $tenants,
        private SpecIdentifierFactory $identifiers,
        private TenantPayloadBuilder $payloads,
        private PaginationPayloadBuilder $pagination,
        private ApiActorScopeService $actors,
    ) {}

    public function list(int $page, int $perPage): array
    {
        $tenants = $this->tenants->paginate($this->actors->currentTenantFilters(), $perPage, $page);

        return [
            'data' => $tenants->getCollection()->map(fn (Tenant $tenant): array => $this->payloads->payload($tenant))->all(),
            'pagination' => $this->pagination->payload($tenants),
        ];
    }

    public function show(string $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if (! $tenant instanceof Tenant) {
            throw TenantNotFoundException::withId($tenantId);
        }

        if (! $this->actors->currentCanReadTenant($tenant)) {
            throw AccessDeniedException::forAction('read', 'tenant', $tenantId);
        }

        return $this->payloads->payload($tenant);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): array
    {
        $tenantId = $this->identifiers->identifier($input['id'] ?? null, 'tenant');
        $tenant = $this->tenants->create([
            'id' => $tenantId,
            'name' => $this->identifiers->displayName($tenantId, $input['name'] ?? null),
            'metadata_json' => $input['metadata'] ?? null,
        ]);

        $tenant->loadCount(['applications', 'groups', 'heaps']);

        return $this->payloads->payload($tenant);
    }
}
