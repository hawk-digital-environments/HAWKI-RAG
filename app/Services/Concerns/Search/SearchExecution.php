<?php

namespace App\Services\Concerns\Search; 

trait SearchExecution
{
    /**
     * Perform search with fallback options
     */
    protected function performSearch(string $term, ?array &$performanceData): array
    {
        $this->ensurePerformanceData($performanceData);
        
        try {
            // Using measureTime to track search performance
            $results = $this->measureTime(function() use ($term) {
                return $this->searchService->search($term);
            }, $performanceData, 'search_ms');
            
        } catch (\Exception $e) {
            $results = rescue(function() use ($term, $performanceData) {
                // Simplify the search term by taking only keywords
                $simplifiedTerm = preg_replace('/[^\w\s]/', '', $term);
                $keywords = preg_split('/\s+/', $simplifiedTerm, -1, PREG_SPLIT_NO_EMPTY);
                $fallbackTerm = implode(' ', array_slice($keywords, 0, min(3, count($keywords))));
                
                // Try the search again with the simplified term
                $results = $this->measureTime(function() use ($fallbackTerm) {
                    return $this->searchService->search($fallbackTerm);
                }, $performanceData, 'search_ms');
                
                $performanceData['search_fallback'] = true;
                return $results;
                
            }, function() use ($performanceData) {
                // Last resort: get some recent items directly from database
                $results = $this->measureTime(function() {
                    $items = \App\Models\Embedding::withoutBinary()
                        ->orderBy('id', 'desc')
                        ->take(5)
                        ->get();
                    
                    return [
                        'items' => $items,
                        'fallback_mode' => true
                    ];
                }, $performanceData, 'search_ms');
                
                $performanceData['search_database_fallback'] = true;
                return $results;
            });
        }
        
        return $results;
    }
} 