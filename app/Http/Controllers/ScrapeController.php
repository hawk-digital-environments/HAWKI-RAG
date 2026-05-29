<?php

namespace App\Http\Controllers;

use App\Services\ScrapeService\ScrapeService;
use App\Services\ScrapeService\Data\ScrapeRequestResult;
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
            'label' => 'nullable|string',
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
        $payload = [
            'success' => $result->success,
            'jobId' => $result->jobId,
            'result' => $result->toArray(),
        ];

        if (!$result->success) {
            $payload['message'] = $this->scrapeFailureMessage($result);
        }

        return response()->json($payload, $result->success ? 200 : ($result->stage === 'validation' ? 422 : 502));
    }

    public function cancelScrape(Request $request){
        $validatedData = $request->validate([
            'jobId' => 'required|string',
        ]);
        $data = $this->scrapeService->stopPipeline($validatedData['jobId']);
        return $this->crawlerResponse($data);
    }

    public function getCrawlerJobs()
    {
        return $this->crawlerResponse($this->scrapeService->listCrawlerJobs());
    }

    public function getCrawlerStatus(string $jobId)
    {
        return $this->crawlerResponse($this->scrapeService->getCrawlerStatus($jobId));
    }

    public function cancelCrawlerJob(string $jobId)
    {
        return $this->crawlerResponse($this->scrapeService->cancelCrawlerJob($jobId));
    }

    public function pauseCrawlerJob(string $jobId)
    {
        return $this->crawlerResponse($this->scrapeService->pauseCrawlerJob($jobId));
    }

    public function resumeCrawlerJob(string $jobId)
    {
        return $this->crawlerResponse($this->scrapeService->resumeCrawlerJob($jobId));
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
        $success = $this->scrapeService->deleteScrapeJob($validatedData['jobId']);
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
            'elementId' => 'required|integer|min:1',
        ]);
        $data = $this->scrapeService->getScrapeResult($validatedData['jobId'], (int) $validatedData['elementId']);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function extractPageContent(Request $request){
        $validatedData = $request->validate([
            'url' => 'required|string',
        ]);
        return $this->crawlerResponse($this->scrapeService->extractPageContent($validatedData['url']));
    }

    private function crawlerResponse(array $result)
    {
        $status = (int) ($result['status'] ?? 502);
        if ($status < 100 || $status > 599) {
            $status = ($result['success'] ?? false) ? 200 : 502;
        }

        return response()->json($result, $status);
    }

    private function scrapeFailureMessage(ScrapeRequestResult $result): string
    {
        $firstError = $result->errors[0] ?? null;

        if (is_array($firstError) && isset($firstError['message']) && is_scalar($firstError['message'])) {
            return (string) $firstError['message'];
        }

        if (is_scalar($firstError)) {
            return (string) $firstError;
        }

        return 'Scrape request failed.';
    }

}
