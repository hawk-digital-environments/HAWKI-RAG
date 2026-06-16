<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\CreateDatasetRequest;
use App\Http\Requests\Dataset\ListDatasetsRequest;
use App\Services\Dataset\DatasetService;
use Illuminate\Http\JsonResponse;

class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetService $datasets,
    ) {
    }

    public function index(ListDatasetsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'datasets' => $this->datasets->list($request->limit()),
        ]);
    }

    public function store(CreateDatasetRequest $request): JsonResponse
    {
        $dataset = $this->datasets->create($request->validated());

        return response()->json([
            'success' => true,
            'datasetId' => $dataset->dataset_id,
            'dataset' => $this->datasets->show($dataset->dataset_id),
        ], 201);
    }

    public function show(string $datasetId): JsonResponse
    {
        $dataset = $this->datasets->show($datasetId);
        if (!$dataset) {
            return response()->json([
                'success' => false,
                'message' => "Dataset {$datasetId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'dataset' => $dataset,
        ]);
    }

    public function destroyStorage(string $datasetId): JsonResponse
    {
        $result = $this->datasets->deleteStorage($datasetId);
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => "Dataset {$datasetId} was not found.",
            ], 404);
        }

        $ok = ($result['qdrant']['ok'] ?? false) && ($result['neo4j']['ok'] ?? false);

        return response()->json([
            'success' => $ok,
            'message' => $ok
                ? "Deleted external storage for dataset {$datasetId}."
                : "External storage cleanup failed for dataset {$datasetId}.",
            'cleanup' => $result,
        ], $ok ? 200 : 502);
    }
}
