<?php

namespace App\Services\ScrapeService;

use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeService
{


    /** ----------------
     *  PIPELINE CONTROLS
     --------------- **/

    /**
     * Start the scraping pipeline
     *
     * @param array $request
     * @param callable|null $outputCallback
     * @return void
     */
    public function startPipeline(array $request, ?callable $outputCallback = null): void
    {
        Log::debug('startPipeline');
        $jobRequest = new ScrapeJobRequest(
            url: $request['url'],
            label: $request['label'],
            maxPages: $request['maxPages'],
            outputDir: $request['outputDir'],
            skipImages: $request['skipImages'],
            imageExceptions: $request['imageExceptions'],
            dateSelector: $request['dateSelector'],
            maxConcurrency: $request['maxConcurrency'],
            maxRpm: $request['maxRpm'],
            requestDelay: $request['requestDelay'],
            discoveryMode: $request['discoveryMode'] ?? false,
        );
        $pipeline = app(ScraperPipelineService::class);
        $pipeline->execute($jobRequest, $outputCallback);
    }

    /**
     *  STOP the scraping pipeline
     *  NOT IMPLIMENTED YET
     *  @todo Create a stop mechanism for scrape process
     *
     * @param string $jobId
     * @return void
     */
    public function stopPipeline(string $jobId): bool
    {

        return false;
    }


    /** ------------------
     *  SCRAPE INFORMATION
    ------------------- **/

    /**
     * List all scrape processes
     * @return array
     */
    public function listScrapeJobs(): array{
        return ScrapeProcess::all()->toArray();
    }

    /**
     * Delete scraped Data
     * @param string $jobId
     * @throws Exception
     */
    public function deleteScrapeJob(string $jobId): bool
    {
        try{
            $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
            $elements = $process->elements;
            foreach ($elements as $element) {
                $element->delete();
            }
            $metadata = $process->metadata;
            foreach ($metadata as $metadataItem) {
                $metadataItem->delete();
            }
            $process->delete();
            return true;
        }
        catch (Exception $exception){
            Log::error('failed to delete scrape job '.$jobId.': '.$exception->getMessage());
            return false;
        }
    }

    /**
     * Removes scraped files but keeps the database information
     * For after the time when data is already vectorized.
     *
     * @param string $jobId
     * @return void
     */
    public function deleteScrapeContent(string $jobId): bool
    {
        return false;
    }


    /**
     * Get Specific Scrape Process information.
     * @param string $jobId
     * @return array
     */
    public function getScrapeInformation(string $jobId): array{
        $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
        $metadata = $process->metadata->toArray();
        $data = $process->toArray();
        $data['metadata'] = $metadata;
        return $data;
    }

    /**
     * Get Specific ScrapedElement
     * @todo extract the file from the storage
     * @param string $jobId
     * @param int $elementId
     * @return array
     */
    public function getScrapeResult(string $jobId, int $elementId): array
    {
        $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
        $element = $process->elements()->findOrFail($elementId);
        return $element;
    }




    /** ------------------
     *  Extract Page Content
    ------------------- **/

    /**
     * extracts the content of a specific page.
     * @param string $url
     * @return string
     * @throws ConnectionException
     */
    public function extractPageContent(string $url): string
    {
        try{
            $response = Http::timeout(300)
                ->post(config('scraper.api_url'). '/scrape',
                    $url);

            Log::debug($response->getBody());
            return $response->getBody();
        }
        catch (ConnectionException $exception){
            Log::error('failed to extract page content '.$exception->getMessage());
            return '';
        }
    }


}
