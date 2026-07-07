<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateHeapRequest;
use App\Http\Requests\SpecV2\ListHeapsRequest;
use App\Http\Requests\SpecV2\UpdateHeapRequest;
use App\Http\Resources\SpecV2\HeapCollection;
use App\Http\Resources\SpecV2\HeapResource;
use App\Services\Authorization\ApiActorResolver;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HeapController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListHeapsRequest $request): JsonResponse
    {
        return response()->json(
            (new HeapCollection($this->spec->heaps->list($request->filters(), $request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function store(CreateHeapRequest $request, ApiActorResolver $actors): JsonResponse
    {
        $heap = $this->spec->heaps->create($request->validated(), $actors->resolve($request));

        return response()->json((new HeapResource($heap))->resolve($request), 201);
    }

    public function show(Request $request, string $heapId): JsonResponse
    {
        return response()->json((new HeapResource($this->spec->heaps->show($heapId)))->resolve($request));
    }

    public function update(string $heapId, UpdateHeapRequest $request): JsonResponse
    {
        return response()->json((new HeapResource($this->spec->heaps->update($heapId, $request->validated())))->resolve($request));
    }

    public function destroy(string $heapId): Response|JsonResponse
    {
        $deleted = $this->spec->heaps->delete($heapId);

        $ok = ($deleted['qdrant']['ok'] ?? false) && ($deleted['neo4j']['ok'] ?? false) && ($deleted['heapDeleted'] ?? false);

        if ($ok) {
            return response()->noContent();
        }

        return response()->json([
            'error' => 'heap_delete_failed',
            'message' => 'Heap cleanup failed.',
            'cleanup' => $deleted,
        ], 502);
    }
}
