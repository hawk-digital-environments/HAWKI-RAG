<?php

namespace App\Services\Search;

use Illuminate\Http\Request;
use App\Services\Search\SearchService;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Providers\GWDGProvider;
use App\Services\Concerns\Search\PerformanceTracking;
use App\Services\Concerns\Search\RequestProcessing;
use App\Services\Concerns\Search\SearchExecution;
use App\Services\Concerns\Search\ResultProcessing;
use App\Services\Concerns\Search\ResponseFormatting;
use Illuminate\Support\Facades\Log;

class StreamSearchService
{
    use PerformanceTracking, RequestProcessing, SearchExecution, ResultProcessing, ResponseFormatting;

    private const STATUS_TYPE = 'ragStatus';
    private const RESPONSE_TYPE = 'ragResponse';
    private const METADATA_TYPE = 'ragMetadata';
    private const ERROR_TYPE = 'ragError';

    private const SEARCH_TIMEOUT = 30;
    private const CHAT_GENERATION_TIMEOUT = 30;

    public function __construct(
        protected SearchService $searchService,
        protected OllamaProvider $ollamaProvider,
        protected GWDGProvider $gwdgProvider
    ) {}

    /**
     * Handle stream search response
     */
    public function handle(Request $request, $requestStartTime = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($request, $requestStartTime) {
            // Disable output buffering
            if (ob_get_level()) ob_end_clean();

            // Performance tracking
            $requestStartTime = $requestStartTime ?? microtime(true);
            $performanceData = [];

            // Function to send chunk in a format compatible with the client
            $sendChunk = function ($content, $isDone = false, $type = null, $metadata = null) use (&$performanceData) {
                $chunk = [
                    'choices' => [
                        [
                            'delta' => ['content' => $content],
                            'finish_reason' => $isDone ? 'stop' : null
                        ]
                    ],
                    'type' => $type
                ];

                when(filled($metadata), function () use (&$chunk, $metadata, $isDone, &$performanceData) {
                    if ($isDone && is_array($metadata)) {
                        $metadata['performance'] = $performanceData;
                    }
                    $chunk['metadata'] = $metadata;
                });

                echo json_encode($chunk) . "\n";
                flush();
            };

            // Function to send error responses in a consistent format
            $sendErrorChunk = function (string $message, int $statusCode = 500, ?array $errors = null)
            use ($sendChunk, &$performanceData, $requestStartTime) {

                $this->addTotalDuration($performanceData, $requestStartTime);
                $performanceData['error'] = true;

                $errorResponse = $this->formatErrorResponse($message, $statusCode, $errors, $performanceData);
                $sendChunk($message, true, self::ERROR_TYPE, $errorResponse);
            };

            try {
                [$validatedData, $validationTime, $unexpectedParams] = $this->validateSearchRequest($request);
                $performanceData['validation_ms'] = $validationTime;

                // Check for unexpected parameters
                if (filled($unexpectedParams)) {
                    $sendErrorChunk(
                        "Invalid parameters. Only 'term', 'messages', or 'stream' parameters are allowed",
                        422,
                        ['Only "term", "messages", or "stream" parameters are allowed']
                    );
                    return;
                }

                $sendChunk("Validating sent data...", false, self::STATUS_TYPE);

                if ($request->has('messages')) {
                    $this->handleMessageBasedStreamSearch($request, $validatedData, $performanceData, $sendChunk, $requestStartTime);
                } else {
                    $this->handleDirectTermStreamSearch($validatedData, $performanceData, $sendChunk, $requestStartTime);
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Streamed validation failed', ['errors' => $e->errors()]);
                $sendErrorChunk('Validation failed', 422, $e->errors());
            } catch (\Exception $e) {
                Log::error('Streamed search processing failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                $sendErrorChunk('Search processing failed: ' . $e->getMessage());
            }
        });

        return tap($response, function ($response) {
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('Cache-Control', 'no-cache');
            $response->headers->set('Connection', 'keep-alive');
            $response->headers->set('X-Accel-Buffering', 'no');
        });
    }

    /**
     * Handle message-based stream search
     */
    private function handleMessageBasedStreamSearch($request, $validatedData, &$performanceData, $sendChunk, $requestStartTime)
    {
        $sendChunk("Generating term from message...", false, self::STATUS_TYPE);

        $term = $this->getSearchTerm($validatedData, $performanceData);

        when(
            data_get($performanceData, 'term_generation_fallback'),
            fn() => $sendChunk("Falling back to user message as search term.", false, self::STATUS_TYPE)
        );

        $sendChunk("Searching for term...", false, self::STATUS_TYPE);
        $this->setTimeLimit(self::SEARCH_TIMEOUT);

        $results = $this->performSearch($term, $performanceData);

        when(
            data_get($performanceData, 'search_fallback'),
            fn() => $sendChunk("Search is taking longer than expected, trying simplified approach...", false, self::STATUS_TYPE)
        );

        when(
            data_get($performanceData, 'search_database_fallback'),
            fn() => $sendChunk("Using fallback results from database...", false, self::STATUS_TYPE)
        );

        $resultsCount = $this->getResultsCount($results);
        $sendChunk("Reading search results. Found related items...", false, self::STATUS_TYPE);

        if ($this->hasResults($results)) {
            $this->generateStreamChatResponse($validatedData, $results, $sendChunk, $term, $resultsCount, $performanceData, $requestStartTime);
        } else {
            $this->addTotalDuration($performanceData, $requestStartTime);
            $sendChunk("No results found for the given search term.", true, self::RESPONSE_TYPE, [
                'status' => 'success',
                'results_count' => 0,
                'term' => $term
            ]);
        }
    }

    /**
     * Handle direct term stream search
     */
    private function handleDirectTermStreamSearch($validatedData, &$performanceData, $sendChunk, $requestStartTime)
    {
        $term = data_get($validatedData, 'term', '');
        $sendChunk("Searching for term: " . $term, false, self::STATUS_TYPE);

        $this->setTimeLimit(self::SEARCH_TIMEOUT);
        $results = $this->performSearch($term, $performanceData);
        $resultsCount = $this->getResultsCount($results);

        $this->addTotalDuration($performanceData, $requestStartTime);

        $sendChunk("Reading search results. Found related items...", true, self::STATUS_TYPE, [
            'status' => 'success',
            'results_count' => $resultsCount,
            'term' => $term,
            'data' => $results
        ]);
    }

    /**
     * Generate stream chat response
     */
    private function generateStreamChatResponse($validatedData, $results, $sendChunk, $term, $resultsCount, &$performanceData, $requestStartTime)
    {
        $sendChunk("Generating a chat response...", false, self::STATUS_TYPE);

        $chatGenStartTime = microtime(true);
        $this->setTimeLimit(self::CHAT_GENERATION_TIMEOUT);

        $this->gwdgProvider->generateStreamChatResponse(
            $validatedData['messages'],
            $results,
            function ($content, $isDone) use ($sendChunk, $resultsCount, $term, $chatGenStartTime, &$performanceData, $requestStartTime) {
                if ($isDone) {
                    $performanceData['chat_generation_ms'] = round((microtime(true) - $chatGenStartTime) * 1000);
                    $this->addTotalDuration($performanceData, $requestStartTime);

                    $sendChunk("", true, self::METADATA_TYPE, [
                        'status' => 'success',
                        'results_count' => $resultsCount,
                        'term' => $term
                    ]);
                } else {
                    $sendChunk($content, false, self::RESPONSE_TYPE);
                }
            }
        );
    }
}
