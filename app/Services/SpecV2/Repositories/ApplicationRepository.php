<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Application;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class ApplicationRepository
{
    public function paginate(?string $tenantId, int $perPage, int $page): LengthAwarePaginator
    {
        return Application::query()
            ->withCount(['heaps', 'groups'])
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $applicationId): ?Application
    {
        return Application::query()
            ->withCount(['heaps', 'groups'])
            ->where('id', $applicationId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Application
    {
        return Application::query()->create($attributes);
    }
}
