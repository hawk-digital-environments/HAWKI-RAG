<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Search\NonStreamSearchService;
use App\Services\Search\StreamSearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private NonStreamSearchService $nonStreamService,
        private StreamSearchService $streamService
    ) {}

    /**
     * Search the vector database via API
     */
    public function search(Request $request): \Symfony\Component\HttpFoundation\Response
    {        
        // Start measuring total request time
        $requestStartTime = microtime(true);
        
        return when(
            $request->boolean('stream'),
            fn() => $this->streamService->handle($request, $requestStartTime),
            fn() => $this->nonStreamService->handle($request, $requestStartTime)
        );
    }
}