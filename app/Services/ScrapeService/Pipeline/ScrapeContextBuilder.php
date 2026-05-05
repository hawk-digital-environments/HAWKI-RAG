<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapeProcess;
use App\Models\ScrapeStatistics;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeContextBuilder
{
    /**
    * For initializing a new process from the pipeline
    *
    * @param ScrapeJobRequest $request
    * @return ScrapeContext
    **/
    public static function buildFromRequest(ScrapeJobRequest $request): ScrapeContext{
        // Generate job_id first
        $jobId = Str::uuid()->toString();

        // Recreate request with the job_id
        $requestWithJobId = ScrapeJobRequest::fromArray(array_merge(
            $request->toArray(),
            ['job_id' => $jobId]
        ));
        $process = ScrapeProcess::create(
            [
            'job_id' => $jobId,
            'url' => $request->url,
            'label' => $request->label,
            'stage' => 'initialized',
            'request' => $requestWithJobId->toArray(),
        ]);
        ScrapeStatistics::create(
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
    public static function rebuildContext(string $jobId): ScrapeContext{

        $process = Cache::get("scrape_process:{$jobId}");
        if(!$process){
            $process = ScrapeProcess::where('job_id', $jobId)->first();
        }

        if(!$process) {
            Log::error('Scrape Process is not initialized correctly or could not be found!');
            throw new Exception("Scrape process '{$jobId}' not found");
        }

        return new ScrapeContext($process);
    }
}
