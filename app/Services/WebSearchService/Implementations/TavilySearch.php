<?php

namespace App\Services\WebSearchService\Implementations;

use App\Services\WebSearchService\Exception\WebSearchFailedException;
use App\Services\WebSearchService\Interface\WebSearchInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

    public function getResponseSchema(JsonSchema $schema): array
    {
        return [
            'instructions' => $schema->string()
                ->description('Contains a set of REALLY IMPORTANT instruction you have to follow with the given response set.'),
            'response' => $schema->object([
                'success' => $schema->boolean()
                    ->description('Indicates the request was processed successfully.'),

                'results' => $schema->object([
                    'query' => $schema->string()
                        ->description('The search query that was executed.'),
                    'follow_up_questions' => $schema->array()
                        ->description('Suggested follow-up questions related to the query.')
                        ->items($schema->string())
                        ->nullable(),
                    'answer' => $schema->string()
                        ->description('LLM-generated answer when include_answer is requested.')
                        ->nullable(),

                    'images' => $schema->array()
                        ->description('List of query-related images; includes description when include_image_descriptions is true.')
                        ->items(
                            $schema->object([
                                'url' => $schema->string()->format('uri')
                                    ->description('Image URL.'),
                                'description' => $schema->string()
                                    ->description('Descriptive text for the image (only when include_image_descriptions is true).')
                                    ->nullable(),
                            ])
                        ),

                    'results' => $schema->array()
                        ->description('Sorted search results ranked by relevancy.')
                        ->items(
                            $schema->object([
                                'title' => $schema->string()
                                    ->description('Title of the search result.'),
                                'url' => $schema->string()->format('uri')
                                    ->description('URL of the search result.'),
                                'content' => $schema->string()
                                    ->description('Short description or snippet from the result.'),
                                'score' => $schema->number()
                                    ->description('Relevance score for the result.'),
                                'raw_content' => $schema->string()
                                    ->description('Cleaned and parsed HTML/text content; present only when include_raw_content is true.')
                                    ->nullable(),
                                'favicon' => $schema->string()->format('uri')
                                    ->description('Favicon URL for the result.')
                                    ->nullable(),
                            ])
                        ),

                    'auto_parameters' => $schema->object()
                        ->description('Auto-selected parameters when auto_parameters is true.')
                        ->nullable(),

                    'response_time' => $schema->number()
                        ->description('Time in seconds to complete the request.'),
                    'request_id' => $schema->string()->format('uuid')
                        ->description('Unique request identifier for debugging/support.'),
                ]),
            ])
                ->description('')
        ];
    }

    public function search(string $query, int $maxResults = 5): array
    {
        try {
            return Http::timeout(20)
                ->post($this->apiUrl, [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'max_results' => $maxResults,
                    'include_answer' => true,
                    'search_depth' => 'advanced'
                ])->json();
        } catch (\Throwable $e) {
            throw new WebSearchFailedException('Connection error: ' . $e->getMessage(), $e);
        }
    }
}
