<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\CreateDatasetRequest;
use App\Http\Requests\Dataset\ListDatasetsRequest;
use App\Services\Dataset\DatasetAuthority;
use Illuminate\Http\JsonResponse;

class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetAuthority $datasets,
    ) {}

    public function index(ListDatasetsRequest $request): JsonResponse
    {
        $result = $this->datasets->list($request->limit());

        return response()->json($result['payload'], $result['status']);
    }

    public function store(CreateDatasetRequest $request): JsonResponse
    {
        $result = $this->datasets->create($request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function show(string $datasetId): JsonResponse
    {
        $result = $this->datasets->show($datasetId);

        return response()->json($result['payload'], $result['status']);
    }

    public function destroyStorage(string $datasetId): JsonResponse
    {
        $result = $this->datasets->delete($datasetId);

        return response()->json($result['payload'], $result['status']);
    }
}
