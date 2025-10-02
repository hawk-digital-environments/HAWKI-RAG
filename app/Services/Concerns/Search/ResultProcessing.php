<?php

namespace App\Services\Concerns\Search; 

use Illuminate\Support\Collection;

trait ResultProcessing
{
    /**
     * Calculate the number of results in a search result array
     */
    protected function getResultsCount(array $results): int
    {
        $items = data_get($results, 'items');
        
        if (blank($items)) {
            return 0;
        }
        
        if ($items instanceof Collection) {
            return $items->count();
        }
        
        return count($items);
    }
    
    /**
     * Check if search results have any items
     */
    protected function hasResults(array $results): bool
    {
        return $this->getResultsCount($results) > 0;
    }
    
    /**
     * Process items into a standardized array format
     */
    protected function processItemsToArray($items): array
    {
        if (filled(data_get($items, 'items'))) {
            $items = data_get($items, 'items');
        }
        
        if ($items instanceof Collection) {
            return $items->all();
        }
        
        return (array) $items;
    }
}