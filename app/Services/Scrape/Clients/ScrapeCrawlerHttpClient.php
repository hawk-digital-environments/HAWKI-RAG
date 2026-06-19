<?php

declare(strict_types=1);

namespace App\Services\Scrape\Clients;

use App\Services\Scrape\Exceptions\ScrapeResponseException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class ScrapeCrawlerHttpClient
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
        private ScrapeCrawlerResponseMapper $responses,
    ) {
    }

    public function request(string $method, string $path, array $payload = []): array
    {
        try {
            $url = $this->apiUrl().'/'.ltrim($path, '/');
            $request = $this->http->timeout(30)
                ->withHeaders($this->headers())
                ->retry(2, 500, throw: false);

            $method = strtoupper($method);
            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->send($method, $url, ['json' => $payload]);

            return $this->responses->requestResult($response);
        } catch (\JsonException|ScrapeResponseException $exception) {
            return $this->responses->invalidJsonResult($exception);
        } catch (\Throwable $exception) {
            return $this->responses->exceptionResult($exception);
        }
    }

    private function apiUrl(): string
    {
        return rtrim((string) $this->config->get('scraper.api_url'), '/');
    }

    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $apiKey = trim((string) $this->config->get('scraper.api_key', ''));
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        return $headers;
    }
}
