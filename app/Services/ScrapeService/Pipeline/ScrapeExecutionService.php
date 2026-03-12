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
     * @return array success and message
     * @throws ConnectionException
     */
    public function execute(ScrapeJobRequest $requestConfig, ?callable $outputCallback = null): array
    {
        try{
            $response = Http::timeout(300)
                ->retry(3, 1000)
                ->post(config('scraper.api_url') . '/crawl',
                    $requestConfig->toArray());

            $data = $response->json(); // decode JSON to array

            if(isset($data['event']) && $data['event'] === 'job_submitted'){
                return [
                    'success' => true,
                    'message' => $data['data']['message'] ?? 'No message provided',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $data['data']['message'] ?? 'No message provided',
                ];
            }
        } catch (\Exception $e) {
            // handle errors, maybe log and return error response
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
