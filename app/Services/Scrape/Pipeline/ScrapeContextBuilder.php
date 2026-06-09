<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Repositories\ScrapeProcessRepository;
use App\Services\Scrape\Repositories\ScrapeStatisticsRepository;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeContextBuilder
{
    public function __construct(
        private readonly ScrapeProcessRepository $processes,
        private readonly ScrapeStatisticsRepository $statistics,
    ) {
    }

    /**
    * For initializing a new process from the pipeline
    *
    * @param ScrapeJobRequest $request
    * @return ScrapeContext
    **/
    public function buildFromRequest(ScrapeJobRequest $request): ScrapeContext{
        $jobId = $request->jobId ?: Str::uuid()->toString();

        // Recreate request with the job_id
        $requestWithJobId = ScrapeJobRequest::fromArray(array_merge(
            $request->toArray(),
            ['job_id' => $jobId]
        ));
        $process = $this->processes->create(
            [
            'job_id' => $jobId,
            'url' => $request->url,
            'label' => $request->label,
            'stage' => 'initialized',
            'request' => $requestWithJobId->toArray(),
        ]);
        $this->statistics->create(
            [
                'job_id' => $process->job_id,
                'started_at'=> now(),
                'completed_at'=> null,
                'target_urls' => $request->maxPages,
                'errors'=> [],
                'warnings'=> [],
            ] // The attributes to update/create
        );

        return new ScrapeContext($process);
    }

    /**
     * Rebuild a scrape context from cache or database state.
     *
     * @param string $jobId
     * @return ScrapeContext
     *
     * @throws Exception
     */
    public function rebuildContext(string $jobId): ScrapeContext{

        $process = Cache::get("scrape_process:{$jobId}");
        if(!$process){
            $process = $this->processes->findByJobId($jobId);
        }

        if(!$process) {
            Log::error('Scrape Process is not initialized correctly or could not be found!');
            throw new Exception("Scrape process '{$jobId}' not found");
        }

        return new ScrapeContext($process);
    }
}
