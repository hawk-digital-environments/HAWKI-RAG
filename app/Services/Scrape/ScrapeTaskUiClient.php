<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Scrape\Exceptions\ScrapeResponseException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

class ScrapeTaskUiClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function profiles(): array
    {
        return $this->request('GET', (string) $this->config->get('scraper.task_ui_profiles_path', '/api/profiles'));
    }

    public function tasks(): array
    {
        return $this->request('GET', (string) $this->config->get('scraper.task_ui_tasks_path', '/api/tasks'));
    }

    public function profile(string $profileId): array
    {
        return $this->request('GET', $this->profilePath($profileId));
    }

    public function submit(array $payload): array
    {
        return $this->request('POST', (string) $this->config->get('scraper.task_ui_submit_path', '/api/crawler/submit'), $payload);
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === '') {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Scraper task UI URL is not configured.',
            ];
        }

        try {
            $request = $this->http->timeout(10)->acceptJson()->retry(1, 250, throw: false);
            $method = strtoupper($method);
            $response = $method === 'GET'
                ? $request->get($baseUrl.'/'.ltrim($path, '/'), $payload)
                : $request->send($method, $baseUrl.'/'.ltrim($path, '/'), ['json' => $payload]);

            return $this->responseResult($response);
        } catch (\JsonException|ScrapeResponseException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Scraper task UI returned invalid JSON: '.$exception->getMessage(),
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

    private function responseResult(Response $response): array
    {
        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw ScrapeResponseException::expectedJsonObject('scraper task UI');
        }

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'data' => $data,
            'message' => $response->successful()
                ? $this->successMessage($data)
                : $this->errorMessage($data, $response->status()),
        ];
    }

    private function baseUrl(): string
    {
        $url = trim((string) $this->config->get('scraper.task_ui_url', ''));
        if ($url === '') {
            return '';
        }

        return rtrim(preg_replace('#/tasks/?$#', '', $url) ?? $url, '/');
    }

    private function profilePath(string $profileId): string
    {
        return rtrim((string) $this->config->get('scraper.task_ui_profiles_path', '/api/profiles'), '/')
            .'/'
            .rawurlencode($profileId);
    }

    private function successMessage(array $data): string
    {
        foreach (['message', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return 'Crawler request completed successfully.';
    }

    private function errorMessage(array $data, int $status): string
    {
        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.(is_string($data['detail']) ? $data['detail'] : json_encode($data['detail']));
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['error']) && is_scalar($data['error']) && trim((string) $data['error']) !== '') {
            return (string) $data['error'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }
}
