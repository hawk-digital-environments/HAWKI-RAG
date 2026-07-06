<?php
declare(strict_types=1);

namespace App\Services\Heap\Repositories;

use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class HeapCatalogRepository
{
    /**
     * @return Collection<int, Dataset>
     */
    public function recentWithTasks(int $limit): Collection
    {
        return Dataset::query()
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pipeline_tasks')
                    ->whereColumn('pipeline_tasks.dataset_id', 'datasets.dataset_id');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findByHeapId(string $heapId): ?Dataset
    {
        return Dataset::query()
            ->where('dataset_id', $heapId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Dataset
    {
        return Dataset::query()->create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function firstOrCreate(string $heapId, array $attributes): Dataset
    {
        return Dataset::query()->firstOrCreate(['dataset_id' => $heapId], $attributes);
    }

    public function delete(Dataset $heap): bool
    {
        return (bool) $heap->delete();
    }
}
