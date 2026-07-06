<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait IncludesPagination
{
    /**
     * @return array<string, mixed>
     */
    protected function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
