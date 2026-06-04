<?php

namespace App\Http\Controllers;

use App\Services\Datasets\DatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetService $datasets,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:250',
        ]);

        return response()->json([
            'success' => true,
            'datasets' => $this->datasets->list((int) ($validated['limit'] ?? 50)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dataset_id' => 'nullable|string|max:191',
            'datasetId' => 'nullable|string|max:191',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:64',
            'qdrant_collection' => 'nullable|string|max:191',
            'qdrantCollection' => 'nullable|string|max:191',
            'neo4j_namespace' => 'nullable|string|max:191',
            'neo4jNamespace' => 'nullable|string|max:191',
        ]);

        $dataset = $this->datasets->create($validated);

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
}
