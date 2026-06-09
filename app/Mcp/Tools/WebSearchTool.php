<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Contracts\WebSearchInterface;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Psr\Log\LoggerInterface;

#[Name('web-search-tool')]
#[Description('Run a web search via the configured provider (brave or tavily).')]
class WebSearchTool extends Tool
{
    public function __construct(
        private readonly WebSearchInterface $webSearch
    )
    {

    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('A query string to search the web with'),
            'max_results' => $schema->integer()
                ->description('The maximum number of results to return'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $this->webSearch->getResponseSchema($schema);
    }

    public function handle(Request $request, LoggerInterface $log): Response|ResponseFactory
    {
        ['query' => $query, 'max_results' => $maxResults] = [
            'max_results' => 5,
            ...$request->validate([
                'query' => 'required|string',
                'max_results' => 'integer|min:1|max:20'
            ])
        ];

        try {
            return Response::structured(
                $this->webSearch->search($query, $maxResults)
            );
        } catch (WebSearchFailedException $e){
            $log->error($e->getMessage(), ['exception' => $e]);
            return Response::error(sprintf('Web search failed with error: %s', $e->getMessage()));
        }
    }
}
