<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class SearchTool extends Tool
{
    public function description(): string
    {
        return 'Run a web search via the configured provider (brave or tavily).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')->description('Search query')->required()
            ->string('provider')->description('brave or tavily; defaults to SEARCH_PROVIDER')
            ->integer('max_results')->description('Max results to return');
    }

    public function handle(array $arguments): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        $provider = strtolower((string) ($arguments['provider'] ?? env('SEARCH_PROVIDER', 'brave')));
        $maxResults = (int) ($arguments['max_results'] ?? 5);
        $maxResults = max(1, min(20, $maxResults));

        if ($provider === 'brave') {
            $apiKey = (string) env('BRAVE_SEARCH_API_KEY', '');
            if ($apiKey === '') {
                return ToolResult::error('BRAVE_SEARCH_API_KEY is not set');
            }

            $response = Http::timeout(20)
                ->withHeaders(['X-Subscription-Token' => $apiKey])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query,
                    'count' => $maxResults,
                ]);

            if (! $response->successful()) {
                return ToolResult::error(sprintf('Brave search failed (%s): %s', $response->status(), $response->body()));
            }

            return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
        }

        if ($provider === 'tavily') {
            $apiKey = (string) env('TAVILY_API_KEY', '');
            if ($apiKey === '') {
                return ToolResult::error('TAVILY_API_KEY is not set');
            }

            $response = Http::timeout(20)->post('https://api.tavily.com/search', [
                'api_key' => $apiKey,
                'query' => $query,
                'max_results' => $maxResults,
            ]);

            if (! $response->successful()) {
                return ToolResult::error(sprintf('Tavily search failed (%s): %s', $response->status(), $response->body()));
            }

            return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
        }

        return ToolResult::error('Unknown provider. Set SEARCH_PROVIDER to brave or tavily.');
    }
}
