<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ScrapePipelineCancellationClient
{
    public function __construct(
        private ScrapeContextBuilder $contexts,
        private HttpFactory $http,
        private ConfigRepository $config,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function stop(string $jobId): array
    {
        $context = $this->contexts->rebuildContext($jobId);

        $response = $this->http->timeout(300)
            ->retry(3, 1000)
            ->post($this->apiUrl()."/jobs/$jobId/cancel");

        $data = $response->json();
        $this->logger->debug('scrape.pipeline.stop_response', ['response' => $data]);
        if (($data['success'] ?? false) === true) {
            $context->setStage('stopped');
            $context->addWarning('Process canceled at '.$this->clock->now()->format('Y-m-d H:i:s'));
        }

        return [
            'success' => (bool) ($data['success'] ?? false),
            'message' => $data['message'] ?? 'No message provided',
        ];
    }

    private function apiUrl(): string
    {
        return rtrim((string) $this->config->get('scraper.api_url'), '/');
    }
}
