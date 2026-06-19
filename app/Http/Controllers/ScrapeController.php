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
}
