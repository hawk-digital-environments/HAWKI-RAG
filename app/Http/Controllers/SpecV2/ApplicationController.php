<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateApplicationRequest;
use App\Http\Requests\SpecV2\ListApplicationsRequest;
use App\Http\Resources\SpecV2\ApplicationCollection;
use App\Http\Resources\SpecV2\ApplicationResource;
use App\Services\Authorization\ApiActorResolver;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\TenantNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListApplicationsRequest $request): JsonResponse
    {
        return response()->json(
            (new ApplicationCollection($this->spec->applications->list($request->tenantId(), $request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function store(CreateApplicationRequest $request, ApiActorResolver $actors): JsonResponse
    {
        try {
            $result = $this->spec->applications->create($request->validated(), $actors->resolve($request));
        } catch (TenantNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = (new ApplicationResource($result['application']))->resolve($request);
        $payload['token'] = $result['token'];
        $payload['token_type'] = 'Bearer';

        return response()->json($payload, 201);
    }

    public function show(string $applicationId, Request $request): JsonResponse
    {
        try {
            return response()->json((new ApplicationResource($this->spec->applications->show($applicationId)))->resolve($request));
        } catch (ApplicationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
