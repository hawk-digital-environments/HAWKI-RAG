<?php

namespace App\Services\Concerns\Search; 

use Illuminate\Http\Request;

trait RequestProcessing
{
    /**
     * Validate search request parameters
     */
    protected function validateSearchRequest(Request $request): array
    {
        $validationStartTime = microtime(true);
        
        $validatedData = $request->validate([
            'term' => 'nullable|string',
            'messages' => 'nullable|array',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string',
            'stream' => 'nullable|boolean'
        ]);
        
        $validationTime = round((microtime(true) - $validationStartTime) * 1000);
        
        $allowedParams = ['term', 'messages', 'stream'];
        $receivedParams = array_keys($request->all());
        $unexpectedParams = array_diff($receivedParams, $allowedParams);
        
        return [$validatedData, $validationTime, $unexpectedParams];
    }
    
    /**
     * Get search term from messages or direct term
     */
    protected function getSearchTerm(array $validatedData, ?array &$performanceData): string
    {
        $this->ensurePerformanceData($performanceData);
        
        $lastUserMessage = collect(data_get($validatedData, 'messages', []))
            ->where('role', 'user')
            ->last();
        
        if (filled(data_get($validatedData, 'messages'))) {
            try {
                // Using measureTime to track term generation
                $term = $this->measureTime(function() use ($validatedData) {
                    return $this->ollamaProvider->generateTermFromMessage($validatedData['messages']);
                }, $performanceData, 'term_generation_ms');
                
            } catch (\Exception $e) {
                // Fallback if term generation fails
                $term = data_get($lastUserMessage, 'content', '');
                $performanceData['term_generation_fallback'] = true;
            }
        } else {
            $term = data_get($validatedData, 'term', '');
            $performanceData['term_generation_ms'] = 0;
        }
        
        return $term;
    }
    
    /**
     * Extract search terms from text using simple keyword extraction
     */
    protected function extractKeywords(string $text, int $maxKeywords = 3): array
    {
        $simplifiedTerm = preg_replace('/[^\w\s]/', '', $text);
        $keywords = preg_split('/\s+/', $simplifiedTerm, -1, PREG_SPLIT_NO_EMPTY);
        
        return array_slice($keywords, 0, min($maxKeywords, count($keywords)));
    }
} 