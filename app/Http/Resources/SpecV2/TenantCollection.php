<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Http\Resources\SpecV2\Concerns\IncludesPagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TenantCollection extends ResourceCollection
{
    use IncludesPagination;

    public static $wrap = null;

    public $collects = TenantResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($resource) => $resource->resolve($request))->values()->all(),
            'pagination' => $this->pagination($this->resource),
        ];
    }
}

