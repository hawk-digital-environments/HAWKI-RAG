<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageEmptyResponseFactory
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function stage(string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'started_at' => null,
            'completed_at' => null,
            'counts' => [],
            'errors' => [],
            'retry' => [
                'retry_count' => null,
                'max_retries' => null,
            ],
        ], $extra);
    }
}
