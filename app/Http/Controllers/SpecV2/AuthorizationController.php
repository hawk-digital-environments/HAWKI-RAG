<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\ReplaceGrantAssignmentsRequest;
use App\Http\Requests\SpecV2\UpdateGrantAssignmentsRequest;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class AuthorizationController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function heapGrants(string $heapId): JsonResponse
    {
        return response()->json($this->spec->auth->heapGrants($heapId));
    }

    public function replaceHeapGrants(string $heapId, ReplaceGrantAssignmentsRequest $request): JsonResponse
    {
        return response()->json($this->spec->auth->replaceHeapGrants($heapId, $request->validated('groups')));
    }

    public function updateHeapGrants(string $heapId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json($this->spec->auth->updateHeapGrants($heapId, $payload['add'] ?? [], $payload['remove'] ?? []));
    }

    public function documentGrants(string $documentId): JsonResponse
    {
        return response()->json($this->spec->auth->documentGrants($documentId));
    }

    public function replaceDocumentGrants(string $documentId, ReplaceGrantAssignmentsRequest $request): JsonResponse
    {
        return response()->json($this->spec->auth->replaceDocumentGrants($documentId, $request->validated('groups')));
    }

    public function updateDocumentGrants(string $documentId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json($this->spec->auth->updateDocumentGrants($documentId, $payload['add'] ?? [], $payload['remove'] ?? []));
    }
}
