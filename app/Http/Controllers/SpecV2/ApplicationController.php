<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateApplicationRequest;
use App\Http\Requests\SpecV2\ListApplicationsRequest;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListApplicationsRequest $request): JsonResponse
    {
        return response()->json($this->spec->applications->list($request->tenantId(), $request->page(), $request->perPage()));
    }

    public function store(CreateApplicationRequest $request): JsonResponse
    {
        try {
            $application = $this->spec->applications->create($request->validated(), $request->user());
        } catch (TenantNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($application, 201);
    }

    public function show(string $applicationId): JsonResponse
    {
        try {
            return response()->json($this->spec->applications->show($applicationId));
        } catch (ApplicationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
