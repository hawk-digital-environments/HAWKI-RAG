<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageStatusMerger
{
    public function currentStage(array $scrape, array $convert, array $ingest): string
    {
        if (! in_array($ingest['status'], ['unknown', 'pending', 'skipped', 'completed'], true)) {
            return 'ingest';
        }
        if (! in_array($convert['status'], ['unknown', 'pending', 'skipped', 'completed'], true)) {
            return 'convert';
        }
        if (! in_array($scrape['status'], ['unknown', 'completed'], true)) {
            return 'scrape';
        }
        if ($ingest['status'] === 'completed') {
            return 'ingest';
        }
        if (in_array($convert['status'], ['completed', 'skipped'], true) && in_array($ingest['status'], ['unknown', 'pending'], true)) {
            return 'ingest';
        }
        if ($scrape['status'] === 'completed' && in_array($convert['status'], ['unknown', 'pending', 'skipped'], true)) {
            return 'convert';
        }
        if ($convert['status'] === 'completed') {
            return 'convert';
        }

        return 'scrape';
    }

    public function overallStatus(array $scrape, array $convert, array $ingest): string
    {
        $statuses = [$scrape['status'], $convert['status'], $ingest['status']];
        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }
        if (in_array('partial', $statuses, true)) {
            return 'partial';
        }
        if (in_array('running', $statuses, true) || in_array('processing', $statuses, true) || in_array('received', $statuses, true)) {
            return 'running';
        }
        if ($scrape['status'] === 'completed' && $convert['status'] === 'completed' && $ingest['status'] === 'completed') {
            return 'completed';
        }

        return 'partial';
    }

    public function mergeTrackedStage(array $computed, ?array $tracked): array
    {
        if (! $tracked) {
            return $computed;
        }

        $merged = array_merge($tracked, array_filter($computed, static fn ($value) => $value !== null && $value !== []));
        if (($computed['status'] ?? 'unknown') === 'unknown' && isset($tracked['status'])) {
            $merged['status'] = $tracked['status'];
        }
        $merged['counts'] = array_merge($tracked['counts'] ?? [], $computed['counts'] ?? []);
        $merged['errors'] = array_values(array_filter(array_merge($tracked['errors'] ?? [], $computed['errors'] ?? [])));
        $merged['warnings'] = array_values(array_filter(array_merge($tracked['warnings'] ?? [], $computed['warnings'] ?? [])));

        return $merged;
    }
}
