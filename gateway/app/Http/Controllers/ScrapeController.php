<?php

namespace App\Http\Controllers;

use App\Services\ScrapeService\ScrapeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScrapeController extends Controller
{
    protected $scrapeService;

    public function __construct(ScrapeService $scrapeService){
        $this->scrapeService = $scrapeService;
    }

    public function requestScrape(Request $request){
        $validatedData = $request->validate([
            'url' => 'required|string',
            'label' => 'required|string',
            'maxPages' => 'integer',
            'outputDir' => 'string|nullable',
            'skipImages' => 'boolean:',
            'imageExceptions' => 'boolean|nullable',
            'dateSelector' => 'string|nullable',
            'maxConcurrency' => 'integer',
            'maxRpm' => 'integer',
            'requestDelay' => 'integer',
            'discoveryMode'=> 'boolean',
        ]);
        $result = $this->scrapeService->startPipeline($validatedData);
        return response()->json([
            'success' => true,
            'result' => $result->toArray()
        ]);
    }

    public function cancelScrape(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
        ]);
        $data = $this->scrapeService->stopPipeline($validatedData['jobId']);
        return response()->json([
            'success' => $data['success'],
            'message' => $data['message']
        ]);
    }

    public function getAllScrapes(Request $request){
        $data = $this->scrapeService->listScrapeJobs();
        return response()->json([
            'data' => $data
        ]);
    }

    public function deleteScrapeJob(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
        ]);
        $success = $this->scrapeService->deleteScrapeJob($request->jobId);
        return response()->json([
            'success' => $success,
        ]);
    }

    public function deleteScrapeContent(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
        ]);
        $success = $this->scrapeService->getScrapeInformation($validatedData['jobId']);
        return response()->json([
            'success' => $success,
        ]);
    }

    public function getScrapeInformation(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
        ]);
        $data = $this->scrapeService->getScrapeInformation($validatedData['jobId']);
        return response()->json([
            'data' => $data,
        ]);
    }

    public function getScrapeResult(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
            'elementId' => 'required|string',
        ]);
        $data = $this->scrapeService->getScrapeResult($validatedData['jobId'], $validatedData['elementId']);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function extractPageContent(Request $request){
        $validatedData = $request->validate([
            'url' => 'required|string',
        ]);
        $content = $this->scrapeService->extractPageContent($validatedData['url']);
        return response()->json([$content]);
    }

}
