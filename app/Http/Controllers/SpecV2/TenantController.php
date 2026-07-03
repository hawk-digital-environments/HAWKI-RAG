<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateTenantRequest;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(PaginatedSpecRequest $request): JsonResponse
    {
        return response()->json($this->spec->tenants->list($request->page(), $request->perPage()));
    }

    public function store(CreateTenantRequest $request): JsonResponse
    {
        return response()->json($this->spec->tenants->create($request->validated()), 201);
    }

    public function show(string $tenantId): JsonResponse
    {
        try {
            return response()->json($this->spec->tenants->show($tenantId));
        } catch (TenantNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
