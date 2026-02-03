<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\HawkiRagPipelineJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PipelineController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string',
            'max_pages' => 'sometimes|integer',
            'output_dir' => 'sometimes|string',
            'label' => 'sometimes|string',
            'collection' => 'sometimes|string',
            'skip_images' => 'sometimes|boolean',
            'image_exceptions' => 'sometimes|string',
            'date' => 'sometimes|string',
            'provider' => 'sometimes|string',
            'graph' => 'sometimes|boolean',
            'graph_engine' => 'sometimes|string',
            'distance' => 'sometimes|string',
            'chunk_chars' => 'sometimes|integer',
            'chunk_overlap' => 'sometimes|integer',
            'batch' => 'sometimes|integer',
            'timeout' => 'sometimes|integer',
            'base_url' => 'sometimes|string',
        ]);

        $statusPath = (string) config('hawki_rag.pipeline_status_path', storage_path('logs/pipeline_status.json'));
        File::ensureDirectoryExists(dirname($statusPath));
        $status = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'payload' => $data,
        ];
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        HawkiRagPipelineJob::dispatch($data);

        return response()->json([
            'ok' => true,
            'status' => 'queued',
            'status_path' => $statusPath,
        ]);
    }

    public function status(): JsonResponse
    {
        $statusPath = (string) config('hawki_rag.pipeline_status_path', storage_path('logs/pipeline_status.json'));
        if (!is_file($statusPath)) {
            return response()->json(['ok' => false, 'message' => 'No pipeline status found.'], 404);
        }

        $statusRaw = @file_get_contents($statusPath);
        $status = $statusRaw ? json_decode($statusRaw, true) : null;

        return response()->json([
            'ok' => true,
            'status' => $status,
        ]);
    }
}
