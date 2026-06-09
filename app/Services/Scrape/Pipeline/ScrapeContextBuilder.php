<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Exceptions\ScrapeProcessException;
use App\Services\Scrape\Repositories\ScrapeProcessRepository;
use App\Services\Scrape\Repositories\ScrapeStatisticsRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

class ScrapeContextBuilder
{
    public function __construct(
        private readonly ScrapeProcessRepository $processes,
        private readonly ScrapeStatisticsRepository $statistics,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new Clock,
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
                'started_at'=> $this->clock->now(),
                'completed_at'=> null,
                'target_urls' => $request->maxPages,
                'errors'=> [],
                'warnings'=> [],
            ] // The attributes to update/create
        );

        return new ScrapeContext($process, $this->clock);
    }

    /**
     * Rebuild a scrape context from cache or database state.
     *
     * @param string $jobId
     * @return ScrapeContext
     *
     * @throws ScrapeProcessException
     */
    public function rebuildContext(string $jobId): ScrapeContext{

        $process = $this->cache->get("scrape_process:{$jobId}");
        if(!$process){
            $process = $this->processes->findByJobId($jobId);
        }

        if(!$process) {
            $this->logger->error('Scrape Process is not initialized correctly or could not be found!');
            throw ScrapeProcessException::notFound($jobId);
        }

        return new ScrapeContext($process, $this->clock);
    }
}
