<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Status\PipelineStatusService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineProofSnapshotCollector
{
    public function __construct(
        private PipelineStatusService $statuses,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capture(ConsoleWorkflowIO $io, string $jobId): array
    {
        $snapshots = [];
        $latest = $this->statusSnapshot($jobId, 'initial');
        $snapshots[] = $latest;

        if (! $io->option('watch')) {
            return $snapshots;
        }

        $interval = max(0.5, (float) $io->option('interval'));
        $timeout = max(1, (int) $io->option('timeout'));
        $deadline = $this->clock->now()->modify("+{$timeout} seconds");
        $lastSignature = $this->statusSignature($latest);

        while ($this->clock->now() < $deadline) {
            if ($this->watchIsTerminal($latest['data'] ?? null)) {
                return $snapshots;
            }

            usleep((int) ($interval * 1_000_000));
            $next = $this->statusSnapshot($jobId, 'watch_transition');
            $nextSignature = $this->statusSignature($next);

            if ($nextSignature !== $lastSignature) {
                $snapshots[] = $next;
                $lastSignature = $nextSignature;
                $io->line($this->snapshotLine($next));
            }

            $latest = $next;
        }

        $latest['reason'] = 'watch_timeout';
        if ($this->statusSignature($latest) === $lastSignature) {
            $snapshots[] = $latest;
        }

        return $snapshots;
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

    /**
     * @return array<string, mixed>
     */
    private function statusSnapshot(string $jobId, string $reason): array
    {
        try {
            $data = $this->statuses->show($jobId);

            return [
                'capturedAt' => $this->timestamp(),
                'reason' => $reason,
                'endpoint' => "/pipeline/status/{$jobId}",
                'success' => true,
                'data' => is_array($data) ? $data : [],
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'capturedAt' => $this->timestamp(),
                'reason' => $reason,
                'endpoint' => "/pipeline/status/{$jobId}",
                'success' => false,
                'data' => [],
                'error' => [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function snapshotLine(array $snapshot): string
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

    /**
     * @param array<string, mixed> $snapshot
     */
    private function statusSignature(array $snapshot): string
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

    private function watchIsTerminal(mixed $status): bool
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
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
