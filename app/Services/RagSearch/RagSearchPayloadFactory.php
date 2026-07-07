<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(string $query, int $limit): array
    {
        return [
            'query' => $query,
            'limit' => $limit,
        ];
    }
}
