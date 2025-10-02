<?php

namespace App\Services\Concerns\Search; 

trait ResponseFormatting
{
    /**
     * Format error response consistently for both stream and non-stream
     */
    protected function formatErrorResponse(string $message, int $statusCode = 500, ?array $errors = null, ?array $performanceData = null): array
    {
        $this->ensurePerformanceData($performanceData);
        
        $response = [
            'status' => 'error',
            'message' => $message,
            'status_code' => $statusCode
        ];
        
        when(filled($errors), fn() => $response['errors'] = $errors);
        when(filled($performanceData), fn() => $response['performance'] = $performanceData);
        
        return $response;
    }
} 