<?php

namespace App\Services\Concerns\Search; 

trait PerformanceTracking
{
    /**
     * Ensure that performanceData is initialized as an array
     */
    protected function ensurePerformanceData(?array &$performanceData): void
    {
        if (blank($performanceData)) {
            $performanceData = [];
        }
    }
    
    /**
     * Calculate total duration and add it to performance data
     */
    protected function addTotalDuration(?array &$performanceData, float $startTime): void
    {
        $this->ensurePerformanceData($performanceData);
        $performanceData['total_duration_ms'] = round((microtime(true) - $startTime) * 1000);
    }
    
    /**
     * Set execution time limit with consistent approach
     */
    protected function setTimeLimit(int $seconds): void
    {
        set_time_limit($seconds);
    }
    
    /**
     * Measure execution time of a callback function and record it in performance data
     */
    protected function measureTime(callable $callback, ?array &$performanceData, string $key): mixed
    {
        $this->ensurePerformanceData($performanceData);
        
        $startTime = microtime(true);
        $result = $callback();
        $duration = round((microtime(true) - $startTime) * 1000);
        
        $performanceData[$key] = $duration;
        
        return $result;
    }
} 