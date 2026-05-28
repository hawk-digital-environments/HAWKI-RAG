<?php

namespace App\Services\ScrapeService;

use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeRequestResult;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

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
     * @return ScrapeRequestResult
     */
    public function startPipeline(array $request, ?callable $outputCallback = null): ScrapeRequestResult
    {
        $defaults = config('scraper.defaults', []);

        $jobRequest = new ScrapeJobRequest(
            url: $request['url'],
            label: $request['label'],
            maxPages: (int) ($request['maxPages'] ?? $defaults['max_pages'] ?? 100),
            outputDir: (string) ($request['outputDir'] ?? ''),
            skipImages: $this->boolValue($request['skipImages'] ?? $defaults['skip_images'] ?? false),
            imageExceptions: $this->normalizeImageExceptions($request['imageExceptions'] ?? null),
            dateSelector: $request['dateSelector'] ?? null,
            maxConcurrency: (int) ($request['maxConcurrency'] ?? $defaults['max_concurrency'] ?? 4),
            maxRpm: (int) ($request['maxRpm'] ?? $defaults['max_rpm'] ?? 60),
            requestDelay: isset($request['requestDelay']) ? (int) $request['requestDelay'] : null,
            discoveryMode: $this->boolValue($request['discoveryMode'] ?? $defaults['discovery_mode'] ?? false),
        );
        $pipeline = app(ScraperPipelineService::class);
        return $pipeline->execute($jobRequest, $outputCallback);
    }

    /**
     *  STOP the scraping pipeline
     *  NOT IMPLIMENTED YET
     *  @todo Create a stop mechanism for scrape process
     *
     * @param string $jobId
     * @return void
     */
    public function stopPipeline(string $jobId): array
    {
        $result = $this->cancelCrawlerJob($jobId);

        if ($result['success'] ?? false) {
            ScrapeProcess::where('job_id', $jobId)->update(['stage' => 'cancel_requested']);
        }

        return $result;
    }

    public function listCrawlerJobs(): array
    {
        return $this->crawlerRequest('GET', '/jobs');
    }

    public function getCrawlerStatus(string $jobId): array
    {
        return $this->crawlerRequest('GET', "/status/{$jobId}");
    }

    public function cancelCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/cancel");
    }

    public function pauseCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/pause");
    }

    public function resumeCrawlerJob(string $jobId): array
    {
        return $this->crawlerRequest('POST', "/jobs/{$jobId}/resume");
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
            $process->stats()->delete();
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
        try {
            $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
            $request = $process->request ?? [];
            $outputDir = (string) ($request['output_dir'] ?? $request['outputDir'] ?? '');

            if ($outputDir === '') {
                return true;
            }

            $storageRoot = realpath((string) config('scraper.storage_path'));
            $target = realpath($outputDir);

            if ($storageRoot === false || $target === false) {
                return true;
            }

            if ($target === $storageRoot || !str_starts_with($target, $storageRoot . DIRECTORY_SEPARATOR)) {
                Log::warning("refusing to delete scrape content outside storage root for job {$jobId}", [
                    'storage_root' => $storageRoot,
                    'target' => $target,
                ]);
                return false;
            }

            return File::deleteDirectory($target);
        } catch (Exception $exception) {
            Log::error('failed to delete scrape content '.$jobId.': '.$exception->getMessage());
            return false;
        }
    }


    /**
     * Get Specific Scrape Process information.
     * @param string $jobId
     * @return array
     */
    public function getScrapeInformation(string $jobId): array{
        $process = ScrapeProcess::where('job_id', $jobId)->firstOrFail();
        $data = $process->toArray();
        $data['stats'] = $process->stats;
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
        return $process->elements()->findOrFail($elementId);
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
                ->post(config('scraper.api_url'). '/scrape', [
                    'url' => $url,
                ]);

            return $response->body();
        }
        catch (ConnectionException $exception){
            Log::error('failed to extract page content '.$exception->getMessage());
            return '';
        }
    }

    private function normalizeImageExceptions(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (is_array($value)) {
            $selectors = array_values(array_filter(
                array_map(static fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value),
                static fn ($item) => $item !== ''
            ));

            return $selectors === [] ? null : implode(',', $selectors);
        }

        throw new \InvalidArgumentException('Image exceptions must be a string or an array of CSS selectors.');
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function crawlerRequest(string $method, string $path): array
    {
        try {
            $url = rtrim((string) config('scraper.api_url'), '/') . '/' . ltrim($path, '/');
            $response = Http::timeout(30)
                ->retry(2, 500, throw: false)
                ->send($method, $url);

            $data = $this->decodeJsonResponse($response->body());
            $success = $response->successful();

            return [
                'success' => $success,
                'status' => $response->status(),
                'data' => $data,
                'message' => $success
                    ? $this->successMessageFromCrawlerData($data)
                    : $this->errorMessageFromCrawlerData($data, $response->status()),
            ];
        } catch (JsonException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Crawler returned invalid JSON: '.$exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @throws JsonException
     */
    private function decodeJsonResponse(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new JsonException('Expected JSON object response.');
        }

        return $data;
    }

    private function successMessageFromCrawlerData(array $data): string
    {
        foreach (['message', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return 'Crawler request completed successfully.';
    }

    private function errorMessageFromCrawlerData(array $data, int $status): string
    {
        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.$this->formatFastApiDetail($data['detail']);
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }

    private function formatFastApiDetail(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (!is_array($detail)) {
            return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
        }

        $messages = [];
        foreach ($detail as $item) {
            if (!is_array($item)) {
                continue;
            }

            $location = $item['loc'] ?? [];
            $path = is_array($location) ? implode('.', array_map('strval', $location)) : (string) $location;
            $message = is_scalar($item['msg'] ?? null) ? (string) $item['msg'] : 'validation error';
            $messages[] = $path !== '' ? "{$path}: {$message}" : $message;
        }

        return $messages === []
            ? (json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown validation error')
            : implode('; ', $messages);
    }

}
