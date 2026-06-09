<?php

declare(strict_types=1);

namespace App\Services\WebSearch\Implementations;

use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class BraveSearch implements WebSearchInterface
{
    protected string $apiKey;
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('web_search.services.brave.api_key');
        $this->apiUrl = config('web_search.services.brave.api_url');
        if ($this->apiKey === '' || $this->apiUrl === '') {
            throw new InvalidArgumentException(
                'Brave web search configuration is missing.'
            );
        }
    }

    public function getResponseSchema(JsonSchema $schema): array
    {
        // @todo Needs to be described using: https://api-dashboard.search.brave.com/api-reference/web/search/get
        return [];
    }

    public function search(string $query, int $maxResults = 5): array
    {
        try{
            return Http::timeout(20)
                ->withHeaders(['X-Subscription-Token' => $this->apiKey])
                ->get($this->apiUrl, [
                    'q' => $query,
                    'count' => $maxResults,
                ])->json();
        } catch(\Throwable $e){
            throw new WebSearchFailedException('Connection error: ' . $e->getMessage(), $e);
        }
    }
}
