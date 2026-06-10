<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Scrape\ScrapeControllerResponseFactory;
use App\Http\Controllers\Scrape\ScrapeRequestRules;
use App\Services\Scrape\ScrapeService;
use Illuminate\Http\Request;

class ScrapeController extends Controller
{
    public function __construct(
        protected readonly ScrapeService $scrapeService,
        private readonly ScrapeRequestRules $rules,
        private readonly ScrapeControllerResponseFactory $responses,
    ) {}

    public function requestScrape(Request $request)
    {
        $validatedData = $request->validate($this->rules->scrape());
        $result = $this->scrapeService->startPipeline($validatedData);

        return $this->responses->scrapeRequest($result);
    }

    public function cancelScrape(Request $request)
    {
        $validatedData = $request->validate($this->rules->jobId());
        $data = $this->scrapeService->stopPipeline($validatedData['jobId']);

        return $this->responses->crawler($data);
    }

    public function getCrawlerJobs()
    {
        return $this->responses->crawler($this->scrapeService->listCrawlerJobs());
    }

    public function getCrawlerTasks()
    {
        $result = $this->scrapeService->listCrawlerTasks();

        return response()->json($result, 200);
    }

    public function startCrawlerTask(Request $request)
    {
        $validatedData = $request->validate($this->rules->crawlerTask());

        $result = $this->scrapeService->startCrawlerTask(
            $validatedData['taskId'],
            $validatedData['options'] ?? [],
        );

        return $this->responses->crawler($result);
    }

    public function getCrawlerStatus(string $jobId)
    {
        return $this->responses->crawler($this->scrapeService->getCrawlerStatus($jobId));
    }

    public function cancelCrawlerJob(string $jobId)
    {
        return $this->responses->crawler($this->scrapeService->cancelCrawlerJob($jobId));
    }

    public function pauseCrawlerJob(string $jobId)
    {
        return $this->responses->crawler($this->scrapeService->pauseCrawlerJob($jobId));
    }

    public function resumeCrawlerJob(string $jobId)
    {
        return $this->responses->crawler($this->scrapeService->resumeCrawlerJob($jobId));
    }

    public function getAllScrapes(Request $request)
    {
        $data = $this->scrapeService->listScrapeJobs();

        return $this->responses->data($data);
    }

    public function deleteScrapeJob(Request $request)
    {
        $validatedData = $request->validate($this->rules->jobId());
        $success = $this->scrapeService->deleteScrapeJob($validatedData['jobId']);

        return $this->responses->success($success);
    }

    public function deleteScrapeContent(Request $request)
    {
        $validatedData = $request->validate($this->rules->jobId());
        $success = $this->scrapeService->deleteScrapeContent($validatedData['jobId']);

        return $this->responses->success($success);
    }

    public function getScrapeInformation(Request $request)
    {
        $validatedData = $request->validate($this->rules->jobId());
        $data = $this->scrapeService->getScrapeInformation($validatedData['jobId']);

        return $this->responses->data($data);
    }

    public function getScrapeResult(Request $request)
    {
        $validatedData = $request->validate($this->rules->scrapeResult());
        $data = $this->scrapeService->getScrapeResult($validatedData['jobId'], (int) $validatedData['elementId']);

        return $this->responses->data($data);
    }

    public function extractPageContent(Request $request)
    {
        $validatedData = $request->validate($this->rules->url());

        return $this->responses->crawler($this->scrapeService->extractPageContent($validatedData['url']));
    }
}
