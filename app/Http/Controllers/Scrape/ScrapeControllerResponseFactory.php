<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scrape;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\JsonResponse;

#[Singleton]
readonly class ScrapeControllerResponseFactory
{
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
}
