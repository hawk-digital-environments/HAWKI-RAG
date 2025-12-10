<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeExecutionService
{
    /**
     *
     * @param callable|null $outputCallback Optional callback for streaming output (callable(string $type, string $buffer))
     * @return bool success true or false
     * @throws ConnectionException
     */
    public function execute(ScrapeJobRequest $requestConfig, ?callable $outputCallback = null): bool
    {
        try{
            Log::debug("execute");
            $response = Http::timeout(300)
                ->retry(3, 1000) // Retry up to 3 times with 1 second delay for transient network issues
                ->post(config('scraper.api_url') . '/crawl',
                    $requestConfig->toArray());

            return $response->json()['success'];
        }
        catch (\Exception $exception){
            Log::error($exception->getMessage());
            throw $exception;
        }
    }
}
