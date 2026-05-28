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
            'maxPages' => 'nullable|integer|min:0',
            'outputDir' => 'string|nullable',
            'skipImages' => 'nullable|boolean',
            'imageExceptions' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) && !is_array($value)) {
                        $fail('The '.$attribute.' field must be a string or an array of CSS selectors.');
                    }
                },
            ],
            'dateSelector' => 'string|nullable',
            'maxConcurrency' => 'nullable|integer|min:1',
            'maxRpm' => 'nullable|integer|min:1',
            'requestDelay' => 'nullable|integer|min:0',
            'discoveryMode'=> 'nullable|boolean',
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
        $success = $this->scrapeService->deleteScrapeContent($validatedData['jobId']);
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
