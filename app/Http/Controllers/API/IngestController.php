<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\DeleteCrawledFolderRequest;
use App\Http\Requests\Pipeline\ListDirectIngestLiveRequest;
use App\Http\Requests\Pipeline\StartDirectIngestRequest;
use App\Http\Requests\Pipeline\StopDirectIngestRequest;
use App\Services\Pipeline\DirectIngest\CrawledDataFolderService;
use App\Services\Pipeline\DirectIngest\DirectIngestLaunchService;
use App\Services\Pipeline\DirectIngest\DirectIngestStatusStore;
use App\Services\Pipeline\DirectIngest\DirectIngestStopService;
use App\Services\Pipeline\Values\DirectIngestActionResult;
use Illuminate\Http\JsonResponse;

class IngestController extends Controller
{
    public function folders(CrawledDataFolderService $folders): JsonResponse
    {
        return $this->respond($folders->list());
    }

    public function start(StartDirectIngestRequest $request, DirectIngestLaunchService $launches): JsonResponse
    {
        $result = $launches->launch($request->validated());

        return response()->json($result->payload, $result->status);
    }

    public function stop(StopDirectIngestRequest $request, DirectIngestStopService $stops): JsonResponse
    {
        return $this->respond($stops->stop($request->validated()));
    }

    public function live(ListDirectIngestLiveRequest $request, DirectIngestStatusStore $statuses): JsonResponse
    {
        $data = $request->validated();
        $mode = $statuses->normalizeMode(isset($data['mode']) ? (string) $data['mode'] : 'default');

        return response()->json([
            'ok' => true,
            'live_ingestions' => $statuses->live($mode),
        ]);
    }

    public function deleteFolder(DeleteCrawledFolderRequest $request, CrawledDataFolderService $folders): JsonResponse
    {
        return $this->respond($folders->delete((string) $request->validated('path')));
    }

    private function respond(DirectIngestActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }
}
