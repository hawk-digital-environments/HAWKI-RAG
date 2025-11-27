<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
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

        $process = ScrapeProcess::create([
            'job_id' => Str::uuid()->toString(),
            'url' => $request->url,
            'label' => $request->label,
            'status' => 'initialized',
            'config' => $request->toArray(),
            'started_at' => now(),
        ]);
        return new ScrapeContext($process);
    }

    /**
     * For Updating the context with Redis Events
     *
     * @param ScrapeProcess $process
     * @return ScrapeContext
     *
     * @throws \Exception
     */
    public static function rebuildContext(string $jobId): ScrapeContext{

        $process = Cache::get("scrape_process:{$jobId}");
        if(!$process){
            $process = ScrapeProcess::where('job_id', $jobId)->first();
        }

        if(!$process) {
            Log::error('Scrape Process is not initialized correctly or could not be found!');
            throw new \Exception("Scrape process '{$jobId}' not found");
        }

        return new ScrapeContext($process);
    }
}
