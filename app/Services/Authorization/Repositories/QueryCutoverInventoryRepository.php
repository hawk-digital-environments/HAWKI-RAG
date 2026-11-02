<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/** Read-only operator inventory; never used by the request path. */
#[Singleton]
readonly class QueryCutoverInventoryRepository
{
    /** @return Collection<int, Dataset> */
    public function datasets(): Collection
    {
        return Dataset::query()->orderBy('dataset_id')->get([
            'dataset_id', 'status', 'qdrant_collection', 'neo4j_namespace',
            'embedding_provider', 'embedding_model',
        ]);
    }

    public function activeUser(string $id): ?User
    {
        return User::query()->whereKey($id)->where('isRemoved', false)->first();
    }
}
