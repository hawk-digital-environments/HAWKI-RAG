<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class TextIngestionIdentifierFactory
{
    public function taskId(
        string $datasetId,
        string $idempotencyKey,
    ): string {
        return 'task_text_'.substr(
            hash('sha256', $datasetId.'|'.$idempotencyKey),
            0,
            32,
        );
    }

    public function jobId(string $taskId, string $sourceId): string
    {
        return 'ingest_'.substr(
            hash('sha256', $taskId.'|'.$sourceId),
            0,
            24,
        );
    }

    public function workflowId(string $taskId): string
    {
        return 'ingest-text-'.substr(
            hash('sha256', $taskId),
            0,
            32,
        );
    }
}
