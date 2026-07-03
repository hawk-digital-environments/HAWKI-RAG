<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class PaginationPayloadBuilder
{
    /**
     * @return array{page:int,per_page:int,total:int}
     */
    public function payload(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
