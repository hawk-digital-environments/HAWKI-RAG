<?php

declare(strict_types=1);

namespace App\Services\WebSearch\Implementations;

use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Factory as HttpFactory;

class TavilySearch implements WebSearchInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

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
        $apiKey = $this->apiKey();
        $apiUrl = $this->apiUrl();

        try {
            $response = $this->http->timeout(20)
                ->post($apiUrl, [
                    'api_key' => $apiKey,
                    'query' => $query,
                    'max_results' => $maxResults,
                    'include_answer' => true,
                    'search_depth' => 'advanced'
                ]);

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $exception) {
            throw WebSearchFailedException::connectionFailed('Tavily', $exception);
        }
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) $this->config->get('web_search.services.tavily.api_key', ''));
        if ($apiKey === '') {
            throw WebSearchFailedException::missingConfiguration('Tavily');
        }

        return $apiKey;
    }

    private function apiUrl(): string
    {
        $apiUrl = trim((string) $this->config->get('web_search.services.tavily.api_url', ''));
        if ($apiUrl === '') {
            throw WebSearchFailedException::missingConfiguration('Tavily');
        }

        return $apiUrl;
    }
}
