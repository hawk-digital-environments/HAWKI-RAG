<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\ReplaceGrantAssignmentsRequest;
use App\Http\Requests\SpecV2\UpdateGrantAssignmentsRequest;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        $payload = $request->validated();
        $result = $this->spec->auth->replaceHeapGrants(
            $heapId,
            $payload['users'] ?? [],
            $payload['groups'] ?? [],
        );

        return response()->json($result->payload, $result->status);
    }

    public function updateHeapGrants(string $heapId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json($this->spec->auth->updateHeapGrants(
            $heapId,
            $payload['add_users'] ?? [],
            $payload['remove_users'] ?? [],
            $payload['add_groups'] ?? $payload['add'] ?? [],
            $payload['remove_groups'] ?? $payload['remove'] ?? [],
        ));
    }

    public function deleteHeapGrants(string $heapId): Response
    {
        $this->spec->auth->deleteHeapGrants($heapId);

        return response()->noContent();
    }

    public function documentGrants(string $documentId): JsonResponse
    {
        return response()->json($this->spec->auth->documentGrants($documentId));
    }

    public function replaceDocumentGrants(string $documentId, ReplaceGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->spec->auth->replaceDocumentGrants(
            $documentId,
            $payload['users'] ?? [],
            $payload['groups'] ?? [],
        );

        return response()->json($result->payload, $result->status);
    }

    public function updateDocumentGrants(string $documentId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json($this->spec->auth->updateDocumentGrants(
            $documentId,
            $payload['add_users'] ?? [],
            $payload['remove_users'] ?? [],
            $payload['add_groups'] ?? $payload['add'] ?? [],
            $payload['remove_groups'] ?? $payload['remove'] ?? [],
        ));
    }

    public function deleteDocumentGrants(string $documentId): Response
    {
        $this->spec->auth->deleteDocumentGrants($documentId);

        return response()->noContent();
    }

    public function check(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'user_identifier' => 'required|string|max:255',
            'heap_id' => 'sometimes|string|max:191',
            'document_id' => 'sometimes|string|max:191',
        ]);

        return response()->json($this->spec->auth->checkAccess(
            (string) $payload['user_identifier'],
            $payload['heap_id'] ?? null,
            $payload['document_id'] ?? null,
        ));
    }

    public function heapsByIdentifier(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => 'required|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:250',
        ]);

        return response()->json($this->spec->auth->heapsByIdentifier(
            (string) $payload['identifier'],
            max(1, (int) ($payload['page'] ?? 1)),
            max(1, min(250, (int) ($payload['per_page'] ?? 25))),
        ));
    }
}
