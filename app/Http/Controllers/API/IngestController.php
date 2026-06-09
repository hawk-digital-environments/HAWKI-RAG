<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\DeleteCrawledFolderRequest;
use App\Http\Requests\Pipeline\ListDirectIngestLiveRequest;
use App\Http\Requests\Pipeline\StartDirectIngestRequest;
use App\Http\Requests\Pipeline\StopDirectIngestRequest;
use App\Services\Pipeline\PipelineService;
use App\Services\DirectIngest\Values\DirectIngestActionResult;
use Illuminate\Http\JsonResponse;

class IngestController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function folders(): JsonResponse
    {
        return $this->respond($this->pipeline->crawledFolders->list());
    }

    public function start(StartDirectIngestRequest $request): JsonResponse
    {
        $result = $this->pipeline->directIngestLaunches->launch($request->validated());

        return response()->json($result->payload, $result->status);
    }

    public function stop(StopDirectIngestRequest $request): JsonResponse
    {
        return $this->respond($this->pipeline->directIngestStops->stop($request->validated()));
    }

    public function live(ListDirectIngestLiveRequest $request): JsonResponse
    {
        $data = $request->validated();
        $mode = $this->pipeline->directIngestStatusStore->normalizeMode(isset($data['mode']) ? (string) $data['mode'] : 'default');

        return response()->json([
            'ok' => true,
            'live_ingestions' => $this->pipeline->directIngestStatusStore->live($mode),
        ]);
    }

    public function deleteFolder(DeleteCrawledFolderRequest $request): JsonResponse
    {
        return $this->respond($this->pipeline->crawledFolders->delete((string) $request->validated('path')));
    }

    private function respond(DirectIngestActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }
}
