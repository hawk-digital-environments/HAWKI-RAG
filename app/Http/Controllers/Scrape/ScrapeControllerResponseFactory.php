<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scrape;

use App\Services\Scrape\Data\ScrapeRequestResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\JsonResponse;

#[Singleton]
readonly class ScrapeControllerResponseFactory
{
    public function scrapeRequest(ScrapeRequestResult $result): JsonResponse
    {
        $payload = [
            'success' => $result->success,
            'jobId' => $result->jobId,
            'result' => $result->toArray(),
        ];

        if (! $result->success) {
            $payload['message'] = $this->scrapeFailureMessage($result);
        }

        return response()->json($payload, $result->success ? 200 : ($result->stage === 'validation' ? 422 : 502));
    }

    /**
     * @param array<string, mixed> $result
     */
    public function crawler(array $result): JsonResponse
    {
        $status = (int) ($result['status'] ?? 502);
        if ($status < 100 || $status > 599) {
            $status = ($result['success'] ?? false) ? 200 : 502;
        }

        return response()->json($result, $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function data(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    public function success(bool $success): JsonResponse
    {
        return response()->json(['success' => $success]);
    }

    private function scrapeFailureMessage(ScrapeRequestResult $result): string
    {
        $firstError = $result->errors[0] ?? null;

        if (is_array($firstError) && isset($firstError['message']) && is_scalar($firstError['message'])) {
            return (string) $firstError['message'];
        }

        if (is_scalar($firstError)) {
            return (string) $firstError;
        }

        return 'Scrape request failed.';
    }
}
