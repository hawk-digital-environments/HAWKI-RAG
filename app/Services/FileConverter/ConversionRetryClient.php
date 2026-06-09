<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Container\Attributes\Singleton;
use SplFileInfo;

#[Singleton]
readonly class ConversionRetryClient
{
    /**
     * @return array<string, string>
     */
    public function convert(
        DocumentConverter $converter,
        SplFileInfo $fileInfo,
        int $maxRetries,
        int $retryDelayMs
    ): array {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $converter->requestDocumentToMarkdown($fileInfo);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $message = $exception->getMessage();

                $isTimeout = str_contains($message, 'cURL error 28') || str_contains($message, 'Operation timed out');
                $isServerError = preg_match('/\bHTTP\/?1\.[01]\s+5\d{2}\b/i', $message) === 1 || str_contains($message, ' 5');

                if ($attempt === $maxRetries || (! ($isTimeout || $isServerError))) {
                    break;
                }

                usleep($retryDelayMs * 1000);
                $attempt++;
            }
        }

        throw $lastException ?? new \RuntimeException('Unknown error during conversion.');
    }
}
