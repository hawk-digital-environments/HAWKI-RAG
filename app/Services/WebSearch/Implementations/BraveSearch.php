<?php

declare(strict_types=1);

namespace App\Services\WebSearch\Implementations;

use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Factory as HttpFactory;

class BraveSearch implements WebSearchInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function getResponseSchema(JsonSchema $schema): array
    {
        // @todo Needs to be described using: https://api-dashboard.search.brave.com/api-reference/web/search/get
        return [];
    }

    public function search(string $query, int $maxResults = 5): array
    {
        $apiKey = $this->apiKey();
        $apiUrl = $this->apiUrl();

        try {
            $response = $this->http->timeout(20)
                ->withHeaders(['X-Subscription-Token' => $apiKey])
                ->get($apiUrl, [
                    'q' => $query,
                    'count' => $maxResults,
                ]);

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $exception) {
            throw WebSearchFailedException::connectionFailed('Brave', $exception);
        }
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) $this->config->get('web_search.services.brave.api_key', ''));
        if ($apiKey === '') {
            throw WebSearchFailedException::missingConfiguration('Brave');
        }

        return $apiKey;
    }

    private function apiUrl(): string
    {
        $apiUrl = trim((string) $this->config->get('web_search.services.brave.api_url', ''));
        if ($apiUrl === '') {
            throw WebSearchFailedException::missingConfiguration('Brave');
        }

        return $apiUrl;
    }
}
