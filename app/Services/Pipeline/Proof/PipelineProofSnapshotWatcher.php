<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineProofSnapshotWatcher
{
    public function __construct(
        private PipelineProofSnapshotReader $reader,
        private PipelineProofSnapshotFormatter $formatter,
        private PipelineProofSnapshotSignature $signatures,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return list<array<string, mixed>>
     */
    public function watch(ConsoleWorkflowIO $io, string $jobId, array $snapshots): array
    {
        $latest = $snapshots[array_key_last($snapshots)];
        $interval = max(0.5, (float) $io->option('interval'));
        $timeout = max(1, (int) $io->option('timeout'));
        $deadline = $this->clock->now()->modify("+{$timeout} seconds");
        $lastSignature = $this->signatures->forSnapshot($latest);

        while ($this->clock->now() < $deadline) {
            if ($this->signatures->isTerminal($latest['data'] ?? null)) {
                return $snapshots;
            }

            usleep((int) ($interval * 1_000_000));
            $next = $this->reader->read($jobId, 'watch_transition');
            $nextSignature = $this->signatures->forSnapshot($next);

            if ($nextSignature !== $lastSignature) {
                $snapshots[] = $next;
                $lastSignature = $nextSignature;
                $io->line($this->formatter->line($next));
            }

            $latest = $next;
        }

        $latest['reason'] = 'watch_timeout';
        if ($this->signatures->forSnapshot($latest) === $lastSignature) {
            $snapshots[] = $latest;
        }

        return $snapshots;
    }
}
