<?php

namespace App\Services\WebSearchService\Implementations;

use App\Services\WebSearchService\Interface\WebSearchInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class TavilySearch implements WebSearchInterface
{
    protected string $apiKey;
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('web_search.services.tavily.api_key');
        $this->apiUrl = config('web_search.services.tavily.api_url');
        if ($this->apiKey === '' || $this->apiUrl === '') {
            throw new InvalidArgumentException(
                'Tavily web search configuration is missing.'
            );
        }
    }

    public function search(string $query, int $maxResults = 5): mixed
    {
        // \Log::debug($query);
        try{
            $response =  Http::timeout(20)->post($this->apiUrl, [
                'api_key' => $this->apiKey,
                'query' => $query,
                'max_results' => $maxResults,
            ]);
            // \Log::debug($response->json() ?? ['raw' => $response->body()]);
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
