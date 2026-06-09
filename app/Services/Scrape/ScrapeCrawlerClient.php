<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

class ScrapeCrawlerClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function listJobs(): array
    {
        return $this->request('GET', '/jobs');
    }

    public function status(string $jobId): array
    {
        return $this->request('GET', "/status/{$jobId}");
    }

    public function cancel(string $jobId): array
    {
        return $this->request('POST', "/jobs/{$jobId}/cancel");
    }

    public function pause(string $jobId): array
    {
        return $this->request('POST', "/jobs/{$jobId}/pause");
    }

    public function resume(string $jobId): array
    {
        return $this->request('POST', "/jobs/{$jobId}/resume");
    }

    public function extractPageContent(string $url): array
    {
        try {
            $response = $this->http->timeout(300)
                ->retry(2, 500, throw: false)
                ->post($this->apiUrl().'/scrape', ['url' => $url]);

            $data = $this->decodeJsonResponse($response->body());
            $success = $response->successful() && (bool) ($data['success'] ?? false);

            return [
                'success' => $success,
                'status' => $success ? $response->status() : ($response->successful() ? 502 : $response->status()),
                'data' => $data,
                'message' => $success
                    ? 'Page content extracted successfully.'
                    : $this->errorMessageFromData($data, $response->status()),
            ];
        } catch (\JsonException $exception) {
            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => 'Crawler returned invalid JSON: '.$exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('failed to extract page content '.$exception->getMessage(), ['exception' => $exception]);

            return [
                'success' => false,
                'status' => 502,
                'data' => null,
                'message' => $exception->getMessage(),
            ];
        }
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

            return $this->responseResult($response);
        } catch (\JsonException $exception) {
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

    public function extractJobId(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach (['job_id', 'jobId', 'jobID', 'crawler_job_id', 'crawlerJobId'] as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        foreach (['data', 'job', 'result', 'task'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $jobId = $this->extractJobId($data[$key]);
                if ($jobId !== null) {
                    return $jobId;
                }
            }
        }

        return null;
    }

    public function taskItems(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        foreach ([
            $data['tasks'] ?? null,
            $data['available_tasks'] ?? null,
            $data['availableTasks'] ?? null,
            $data['data']['tasks'] ?? null,
            $data['data']['available_tasks'] ?? null,
            $data['data']['availableTasks'] ?? null,
            $data['data'] ?? null,
        ] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return $this->isList($data) ? $data : [];
    }

    public function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function responseResult(Response $response): array
    {
        $data = $this->decodeJsonResponse($response->body());
        $success = $response->successful();

        return [
            'success' => $success,
            'status' => $response->status(),
            'data' => $data,
            'message' => $success
                ? $this->successMessageFromData($data)
                : $this->errorMessageFromData($data, $response->status()),
        ];
    }

    /**
     * @throws \JsonException
     */
    private function decodeJsonResponse(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \JsonException('Expected JSON object response.');
        }

        return $data;
    }

    private function successMessageFromData(array $data): string
    {
        foreach (['message', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return 'Crawler request completed successfully.';
    }

    private function errorMessageFromData(array $data, int $status): string
    {
        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.$this->formatFastApiDetail($data['detail']);
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['error']) && is_scalar($data['error']) && trim((string) $data['error']) !== '') {
            return (string) $data['error'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }

    private function formatFastApiDetail(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (! is_array($detail)) {
            return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
        }

        $messages = [];
        foreach ($detail as $item) {
            if (! is_array($item)) {
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
