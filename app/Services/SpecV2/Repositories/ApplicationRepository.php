<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Application;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class ApplicationRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return Application::query()
            ->withCount(['heaps', 'groups'])
            ->when($filters['id'] ?? null, fn ($query, $id) => $query->where('id', $id))
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
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
