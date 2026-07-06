<?php
declare(strict_types=1);

namespace App\Services\Compatibility;

use App\Models\Dataset;
use App\Services\Heap\HeapCatalogService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class LegacyDatasetService
{
    public function __construct(
        private HeapCatalogService $heaps,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        return $this->heaps->list($limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(string $datasetId): ?array
    {
        return $this->heaps->show($datasetId);
    }

    public function create(array $input): Dataset
    {
        return $this->heaps->create($input);
    }

    public function ensure(string|array|null $dataset = null, array $input = []): Dataset
    {
        return $this->heaps->ensure($dataset, $input);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delete(string $datasetId): ?array
    {
        return $this->heaps->delete($datasetId);
    }
}
