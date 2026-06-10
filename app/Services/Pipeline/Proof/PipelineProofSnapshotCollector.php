<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofSnapshotCollector
{
    public function __construct(
        private PipelineProofSnapshotReader $reader,
        private PipelineProofSnapshotWatcher $watcher,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capture(ConsoleWorkflowIO $io, string $jobId): array
    {
        $snapshots = [];
        $snapshots[] = $this->reader->read($jobId, 'initial');

        if (! $io->option('watch')) {
            return $snapshots;
        }

        return $this->watcher->watch($io, $jobId, $snapshots);
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, mixed>
     */
    public function latestStatusData(array $snapshots): array
    {
        for ($index = count($snapshots) - 1; $index >= 0; $index--) {
            if (($snapshots[$index]['success'] ?? false) && is_array($snapshots[$index]['data'] ?? null)) {
                return $snapshots[$index]['data'];
            }
        }

        return [];
    }
}
