<?php

namespace App\Http\Controllers;

use App\Services\Pipeline\PipelineProfileService;
use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineProfileController extends Controller
{
    public function __construct(
        private readonly PipelineProfileService $profiles,
        private readonly PipelineTaskService $tasks,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:250',
        ]);

        return response()->json([
            'success' => true,
            'profiles' => $this->profiles->list((int) ($validated['limit'] ?? 100)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedProfile($request);
        $profile = $this->profiles->create($validated);

        return response()->json([
            'success' => true,
            'profileId' => $profile->profile_id,
            'profile' => $this->profiles->payload($profile),
        ], 201);
    }

    public function show(string $profileId): JsonResponse
    {
        $profile = $this->profiles->show($profileId);
        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline profile {$profileId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'profile' => $profile,
        ]);
    }

    public function update(Request $request, string $profileId): JsonResponse
    {
        $profile = $this->profiles->update($profileId, $this->validatedProfile($request, partial: true));
        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline profile {$profileId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'profileId' => $profile->profile_id,
            'profile' => $this->profiles->payload($profile),
        ]);
    }

    public function startTask(Request $request, string $profileId): JsonResponse
    {
        if (!$this->profiles->find($profileId)) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline profile {$profileId} was not found.",
            ], 404);
        }

        $validated = $request->validate([
            'task_id' => 'nullable|string',
            'taskId' => 'nullable|string',
            'dataset_id' => 'nullable|string',
            'datasetId' => 'nullable|string',
            'urls' => 'nullable',
            'metadata' => 'nullable|array',
        ]);
        $validated['pipeline_profile_id'] = $profileId;

        $task = $this->tasks->start($validated);

        return response()->json([
            'success' => true,
            'taskId' => $task->task_id,
            'task' => $this->tasks->show($task->task_id),
        ], 201);
    }

    private function validatedProfile(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'profile_id' => ($partial ? 'nullable' : 'nullable') . '|string|max:191',
            'profileId' => 'nullable|string|max:191',
            'name' => ($partial ? 'nullable' : 'required') . '|string|max:255',
            'description' => 'nullable|string',
            'start_urls' => 'nullable',
            'startUrls' => 'nullable',
            'urls' => 'nullable',
            'sitemap_url' => 'nullable|string',
            'sitemapUrl' => 'nullable|string',
            'max_pages' => 'nullable|integer|min:1|max:100000',
            'maxPages' => 'nullable|integer|min:1|max:100000',
            'allowed_file_types' => 'nullable',
            'allowedFileTypes' => 'nullable',
            'graph_enabled' => 'nullable|boolean',
            'graphEnabled' => 'nullable|boolean',
            'qdrant_collection' => 'nullable|string|max:191',
            'qdrantCollection' => 'nullable|string|max:191',
            'neo4j_namespace' => 'nullable|string|max:191',
            'neo4jNamespace' => 'nullable|string|max:191',
            'metadata' => 'nullable|array',
        ]);
    }
}
