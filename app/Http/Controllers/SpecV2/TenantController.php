<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateTenantRequest;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Http\Resources\SpecV2\TenantCollection;
use App\Http\Resources\SpecV2\TenantResource;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(PaginatedSpecRequest $request): JsonResponse
    {
        return response()->json(
            (new TenantCollection($this->spec->tenants->list($request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function store(CreateTenantRequest $request): JsonResponse
    {
        return response()->json((new TenantResource($this->spec->tenants->create($request->validated())))->resolve($request), 201);
    }

    public function show(string $tenantId, Request $request): JsonResponse
    {
        try {
            return response()->json((new TenantResource($this->spec->tenants->show($tenantId)))->resolve($request));
        } catch (TenantNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
