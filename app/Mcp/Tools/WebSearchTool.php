<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\WebSearchService\Interface\WebSearchInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class WebSearchTool extends Tool
{

    protected WebSearchInterface $webSearchService;
    public function __construct(
        WebSearchInterface $webSearch
    )
    {
        $this->webSearchService = $webSearch;
    }

    public function name(): string
    {
        return 'web-search-tool';
    }

    public function description(): string
    {
        return 'Run a web search via the configured provider (brave or tavily).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')->description('Search query')->required()
            ->integer('max_results')->description('Max results to return');
    }

    public function handle(array $arguments): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        $maxResults = (int) ($arguments['max_results'] ?? 5);
        $maxResults = max(1, min(20, $maxResults));

        $response = $this->webSearchService->search($query, $maxResults);

        if (!$response['success']) {
            return ToolResult::error(sprintf('Web Search failed (%s): %s', json_encode($response['results'])));
        }

        return ToolResult::json($response['results']);
    }
}
