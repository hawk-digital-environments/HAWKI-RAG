<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Services\ScrapeService\Data\ScrapeConfig;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ScrapeExecutionService
{
    /**
     *
     * @param callable|null $outputCallback Optional callback for streaming output (callable(string $type, string $buffer))
     * @return ScrapeEventPacket Result object with success status, output, and any errors
     */
    public function execute(ScrapeJobRequest $requestConfig, ?callable $outputCallback = null): ScrapeEventPacket
    {
        Log::debug("execute");
        $response = Http::timeout(300)
            ->post('http://crawler:8000/crawl',
                        $requestConfig->toArray());


        Log::debug('Crawl4AI HTTP status: ' . $response->status());
        Log::debug('Sitemap URLs: ', $response->json()['sitemap_urls'] ?? []);

        $data = $response->json();

        return new ScrapeEventPacket(
            $data['job_id'],
            $data['event'],
            $data['data'],
            $data['timestamp'],
        );
    }
}
