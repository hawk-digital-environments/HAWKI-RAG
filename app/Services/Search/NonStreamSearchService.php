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

class NonStreamSearchService
{
    use PerformanceTracking, RequestProcessing, SearchExecution, ResultProcessing, ResponseFormatting;
    
    private const SEARCH_TIMEOUT = 30;
    private const CHAT_GENERATION_TIMEOUT = 30;
    
    public function __construct(
        protected SearchService $searchService,
        protected OllamaProvider $ollamaProvider,
        protected GWDGProvider $gwdgProvider
    ) {}
    
    /**
     * Handle non-stream search response
     */
    public function handle(Request $request, $requestStartTime = null): \Symfony\Component\HttpFoundation\Response
    {        
        // Start measuring time if not already started
        $requestStartTime = $requestStartTime ?? microtime(true);
        
        try {
            // Use the validation method from trait
            [$validatedData, $validationTime, $unexpectedParams] = $this->validateSearchRequest($request);
            
            if (filled($unexpectedParams)) {
                $errorResponse = $this->formatErrorResponse(
                    'Invalid parameters', 
                    422, 
                    ['Only "term", "messages", or "stream" parameters are allowed']
                );
                
                return response()->json($errorResponse, 422);
            }

            $chatResponse = null;
            $performanceData = ['validation_ms' => $validationTime];
            
            // Get search term using trait method
            $term = $this->getSearchTerm($validatedData, $performanceData);
            
            // Reset timeout for search operation
            $this->setTimeLimit(self::SEARCH_TIMEOUT);
            
            // Perform search with fallbacks using trait method
            $results = $this->performSearch($term, $performanceData);
            
            $resultsCount = $this->getResultsCount($results);
            
            // Handle chat response generation if we have message-based search with results
            if ($request->has('messages') && $this->hasResults($results)) {
                // Set a timeout for chat response generation
                $this->setTimeLimit(self::CHAT_GENERATION_TIMEOUT);
                
                try {
                    // Generate chat response based on the search results
                    $chatResponse = $this->measureTime(function() use ($validatedData, $results) {
                        return $this->gwdgProvider->generateNonStreamChatResponse(
                            $validatedData['messages'],
                            $results
                        );
                    }, $performanceData, 'chat_generation_ms');
                    
                } catch (\Exception $e) {
                    $chatResponse = "The response took too long to generate. Please try again later.";
                    $performanceData['chat_generation_error'] = true;
                }
            } else {
                $performanceData['chat_generation_ms'] = 0;
            }

            // Calculate total request processing time
            $this->addTotalDuration($performanceData, $requestStartTime);
            
            return response()->json([
                'status' => 'success',
                'term' => $term,
                'data' => $results,
                'chat_response' => $chatResponse,
                'results_count' => $resultsCount,
                'performance' => $performanceData
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $performanceData = [];
            $this->addTotalDuration($performanceData, $requestStartTime);
            
            Log::error('Validation failed', ['errors' => $e->errors()]);
            
            return response()->json(
                $this->formatErrorResponse('Validation failed', 422, $e->errors(), $performanceData),
                422
            );
            
        } catch (\Exception $e) {
            $performanceData = [];
            $this->addTotalDuration($performanceData, $requestStartTime);
            
            Log::error('Search processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(
                $this->formatErrorResponse('Search processing failed: ' . $e->getMessage(), 500, null, $performanceData),
                500
            );
        }
    }
} 