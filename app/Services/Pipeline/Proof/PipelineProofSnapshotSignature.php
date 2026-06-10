<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofSnapshotSignature
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function forSnapshot(array $snapshot): string
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];

        return $this->json([
            'success' => (bool) ($snapshot['success'] ?? false),
            'overall' => $data['status'] ?? null,
            'currentStage' => $data['currentStage'] ?? null,
            'scrape' => $this->stageSignature($stages['scrape'] ?? []),
            'convert' => $this->stageSignature($stages['convert'] ?? []),
            'ingest' => $this->stageSignature($stages['ingest'] ?? []),
            'error' => $snapshot['error']['message'] ?? null,
        ]);
    }

    public function isTerminal(mixed $status): bool
    {
        if (! is_array($status)) {
            return false;
        }

        $stages = is_array($status['stages'] ?? null) ? $status['stages'] : [];
        $stageStatuses = array_filter([
            $stages['scrape']['status'] ?? null,
            $stages['convert']['status'] ?? null,
            $stages['ingest']['status'] ?? null,
        ]);

        if (($status['status'] ?? null) === 'completed'
            && ($stages['scrape']['status'] ?? null) === 'completed'
            && ($stages['convert']['status'] ?? null) === 'completed'
            && ($stages['ingest']['status'] ?? null) === 'completed') {
            return true;
        }

        if (array_intersect($stageStatuses, ['running', 'processing', 'received', 'pending'])) {
            return false;
        }

        return (bool) array_intersect($stageStatuses, ['failed', 'partial', 'skipped']);
    }

    /**
     * @return array<string, mixed>
     */
    private function stageSignature(mixed $stage): array
    {
        $stage = is_array($stage) ? $stage : [];

        return [
            'status' => $stage['status'] ?? null,
            'counts' => $stage['counts'] ?? [],
            'retry' => $stage['retry'] ?? [],
            'errorCount' => is_array($stage['errors'] ?? null) ? count($stage['errors']) : 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
