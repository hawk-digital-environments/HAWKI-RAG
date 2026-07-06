<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\ReplaceGrantAssignmentsRequest;
use App\Http\Requests\SpecV2\UpdateGrantAssignmentsRequest;
use App\Services\SpecV2\Exceptions\AuthorizationGrantException;
use App\Services\SpecV2\Exceptions\GroupNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class AuthorizationController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function heapGrants(string $heapId): JsonResponse
    {
        try {
            return response()->json($this->spec->auth->heapGrants($heapId));
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function replaceHeapGrants(string $heapId, ReplaceGrantAssignmentsRequest $request): JsonResponse
    {
        try {
            return response()->json($this->spec->auth->replaceHeapGrants($heapId, $request->validated('groups')));
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (GroupNotFoundException|AuthorizationGrantException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateHeapGrants(string $heapId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            return response()->json($this->spec->auth->updateHeapGrants($heapId, $payload['add'] ?? [], $payload['remove'] ?? []));
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (GroupNotFoundException|AuthorizationGrantException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function documentGrants(string $documentId): JsonResponse
    {
        try {
            return response()->json($this->spec->auth->documentGrants($documentId));
        } catch (AuthorizationGrantException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function replaceDocumentGrants(string $documentId, ReplaceGrantAssignmentsRequest $request): JsonResponse
    {
        try {
            return response()->json($this->spec->auth->replaceDocumentGrants($documentId, $request->validated('groups')));
        } catch (AuthorizationGrantException $e) {
            $status = str_contains($e->getMessage(), 'was not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateDocumentGrants(string $documentId, UpdateGrantAssignmentsRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            return response()->json($this->spec->auth->updateDocumentGrants($documentId, $payload['add'] ?? [], $payload['remove'] ?? []));
        } catch (AuthorizationGrantException $e) {
            $status = str_contains($e->getMessage(), 'was not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
