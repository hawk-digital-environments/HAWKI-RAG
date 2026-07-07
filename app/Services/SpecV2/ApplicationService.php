<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Application;
use App\Services\Authorization\ApiActor;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\Authorization\ApplicationTokenService;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\Payloads\ApplicationPayloadBuilder;
use App\Services\SpecV2\Payloads\PaginationPayloadBuilder;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\TenantRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ApplicationService
{
    public function __construct(
        private ApplicationRepository $applications,
        private TenantRepository $tenants,
        private ApplicationTokenService $tokens,
        private SpecIdentifierFactory $identifiers,
        private ApplicationPayloadBuilder $payloads,
        private PaginationPayloadBuilder $pagination,
        private ApiActorScopeService $actors,
    ) {}

    public function list(?string $tenantId, int $page, int $perPage): array
    {
        $filters = $this->actors->currentApplicationFilters();
        if ($tenantId !== null) {
            $filters['tenant_id'] = $tenantId;
        }

        $applications = $this->applications->paginate($filters, $perPage, $page);

        return [
            'data' => $applications->getCollection()->map(fn (Application $application): array => $this->payloads->payload($application))->all(),
            'pagination' => $this->pagination->payload($applications),
        ];
    }

    public function show(string $applicationId): array
    {
        $application = $this->applications->findById($applicationId);
        if (! $application instanceof Application) {
            throw ApplicationNotFoundException::withId($applicationId);
        }

        if (! $this->actors->currentCanReadApplication($application)) {
            throw AccessDeniedException::forAction('read', 'application', $applicationId);
        }

        return $this->payloads->payload($application);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?ApiActor $actor = null): array
    {
        $tenantId = $this->identifiers->stringValue($input['tenant_id'] ?? null)
            ?? $actor?->tenantId()
            ?? 'default';
        if ($this->tenants->findById($tenantId) === null) {
            throw TenantNotFoundException::withId($tenantId);
        }

        $applicationId = $this->identifiers->identifier($input['id'] ?? null, 'app');
        $permissions = $this->permissions($input['permissions'] ?? null);

        $application = $this->applications->create([
            'id' => $applicationId,
            'tenant_id' => $tenantId,
            'name' => $this->identifiers->displayName($applicationId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'permissions' => $permissions,
            'token_hash' => null,
            'metadata_json' => $input['metadata'] ?? null,
        ]);

        $application->loadCount(['heaps', 'groups']);
        $token = $this->tokens->issue($application);

        return array_merge($this->payloads->payload($application), [
            'token' => $token,
            'tokenType' => 'Bearer',
        ]);
    }

    /**
     * @param mixed $permissions
     * @return list<string>
     */
    private function permissions(mixed $permissions): array
    {
        $requested = is_array($permissions) ? $permissions : [Application::PERMISSION_READS];
        $allowed = array_flip(Application::allowedPermissions());
        $normalized = [];

        foreach ($requested as $permission) {
            if (is_string($permission) && isset($allowed[$permission])) {
                $normalized[] = $permission;
            }
        }

        if ($normalized === []) {
            $normalized[] = Application::PERMISSION_READS;
        }

        return array_values(array_unique($normalized));
    }
}
