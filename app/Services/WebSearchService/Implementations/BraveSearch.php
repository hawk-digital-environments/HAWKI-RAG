<?php

namespace App\Services\WebSearchService\Implementations;

use App\Services\WebSearchService\Interface\WebSearchInterface;
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



    public function search(string $query, int $maxResults = 5): mixed
    {
        try{
            $response = Http::timeout(20)
                ->withHeaders(['X-Subscription-Token' => $this->apiKey])
                ->get($this->apiUrl, [
                    'q' => $query,
                    'count' => $maxResults,
                ]);
            return [
                'success' => $response->successful(),
                'results' => $response->json() ?? ['raw' => $response->body()],
            ];
        }
        catch(ConnectionException $e){
            return [
                'success' => false,
                'results' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}
