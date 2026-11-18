<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\SaveGraphSnapshotRequest;
use App\Services\Graph\GraphSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraphSnapshotController extends GraphController
{
    public function index(Request $request, GraphSnapshotService $snapshots): JsonResponse
    {
        return $this->noStore(response()->json($snapshots->list($request->user())));
    }

    public function store(SaveGraphSnapshotRequest $request, GraphSnapshotService $snapshots): JsonResponse
    {
        return $this->noStore(response()->json($snapshots->save($request->user(), $request->snapshot())));
    }

    public function show(string $id, Request $request, GraphSnapshotService $snapshots): JsonResponse
    {
        return $this->noStore(response()->json($snapshots->load($request->user(), $id)));
    }

    public function destroy(string $id, Request $request, GraphSnapshotService $snapshots): JsonResponse
    {
        return $this->noStore(response()->json($snapshots->delete($request->user(), $id)));
    }
}
