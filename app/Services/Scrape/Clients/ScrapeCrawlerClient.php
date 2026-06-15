<?php

declare(strict_types=1);

namespace App\Services\Scrape\Clients;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ScrapeCrawlerClient
{
    public function __construct(
        private readonly ScrapeCrawlerHttpClient $http,
        private readonly ScrapeCrawlerPayloadNormalizer $payloads,
    ) {
    }

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
        return $this->http->extractPageContent($url);
    }

    public function request(string $method, string $path, array $payload = []): array
    {
        return $this->http->request($method, $path, $payload);
    }

    public function extractJobId(mixed $data): ?string
    {
        return $this->payloads->extractJobId($data);
    }

    public function taskItems(mixed $data): array
    {
        return $this->payloads->taskItems($data);
    }

    public function firstScalar(array $values): ?string
    {
        return $this->payloads->firstScalar($values);
    }

    public function isList(array $value): bool
    {
        return $this->payloads->isList($value);
    }
}
