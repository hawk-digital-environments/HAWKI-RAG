<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\QdrantStreamSearchService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QdrantSearchController extends Controller
{
    protected QdrantStreamSearchService $streamService;

    public function __construct(QdrantStreamSearchService $streamService)
    {
        $this->streamService = $streamService;
    }

    /**
     * Search Qdrant vector database via API (stream only).
     */
    public function search(Request $request): Response
    {
        $requestStartTime = microtime(true);
        return $this->streamService->handle($request, $requestStartTime);
    }
}
