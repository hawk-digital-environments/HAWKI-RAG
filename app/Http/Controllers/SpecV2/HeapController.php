<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateHeapRequest;
use App\Http\Requests\SpecV2\ListHeapsRequest;
use App\Http\Requests\SpecV2\UpdateHeapRequest;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class HeapController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListHeapsRequest $request): JsonResponse
    {
        return response()->json($this->spec->heaps->list($request->filters(), $request->page(), $request->perPage()));
    }

    public function store(CreateHeapRequest $request): JsonResponse
    {
        try {
            $heap = $this->spec->heaps->create($request->validated(), $request->user());
        } catch (ApplicationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($heap, 201);
    }

    public function show(string $heapId): JsonResponse
    {
        try {
            return response()->json($this->spec->heaps->show($heapId));
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function update(string $heapId, UpdateHeapRequest $request): JsonResponse
    {
        try {
            return response()->json($this->spec->heaps->update($heapId, $request->validated()));
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function destroy(string $heapId): JsonResponse
    {
        try {
            $deleted = $this->spec->heaps->delete($heapId);
        } catch (HeapNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $ok = ($deleted['qdrant']['ok'] ?? false) && ($deleted['neo4j']['ok'] ?? false) && ($deleted['datasetDeleted'] ?? false);

        return response()->json([
            'success' => $ok,
            'cleanup' => $deleted,
        ], $ok ? 200 : 502);
    }
}
