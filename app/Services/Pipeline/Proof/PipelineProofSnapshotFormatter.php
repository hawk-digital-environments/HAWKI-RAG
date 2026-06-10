<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofSnapshotFormatter
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function line(array $snapshot): string
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];

        return sprintf(
            'snapshot %s | overall=%s | scrape=%s | convert=%s | ingest=%s',
            $snapshot['capturedAt'] ?? '',
            $data['status'] ?? 'unknown',
            $stages['scrape']['status'] ?? 'unknown',
            $stages['convert']['status'] ?? 'unknown',
            $stages['ingest']['status'] ?? 'unknown',
        );
    }
}
