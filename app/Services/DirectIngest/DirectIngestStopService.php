<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\Pipeline\State\PipelineStateService;
use App\Services\DirectIngest\Values\DirectIngestActionResult;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DirectIngestStopService
{
    public function __construct(
        private DirectIngestStatusStore $statuses,
        private PipelineStateService $pipelineState,
        private ClockInterface $clock = new Clock(),
    ) {}

    public function stop(array $data): DirectIngestActionResult
    {
        $mode = $this->statuses->normalizeMode((string) ($data['mode'] ?? 'default'));
        $paths = $this->statuses->paths($mode);
        $liveBefore = $this->statuses->live($mode);
        if (! $liveBefore) {
            return DirectIngestActionResult::fromPayload([
                'ok' => true,
                'stopped_count' => 0,
                'stopped_pids' => [],
                'message' => 'No running ingest process found.',
                'live_ingestions' => $liveBefore,
            ]);
        }

        $targetPids = $this->targetPids($data, $liveBefore);
        $stoppedCount = 0;
        $stoppedPids = [];
        foreach ($targetPids as $pid) {
            $pid = (int) $pid;
            $stopped = false;
            if ($pid > 0 && ! $this->isPidAlive($pid)) {
                $stopped = true;
            } elseif ($pid > 0 && function_exists('posix_kill')) {
                $stopped = @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
            }
            if (! $stopped && $pid > 0) {
                @exec('kill -TERM '.$pid, $out, $code);
                $stopped = $code === 0;
            }
            if ($stopped) {
                $stoppedCount += 1;
                $stoppedPids[] = $pid;
            }
        }

        if ($stoppedCount === 0) {
            $stoppedCount = $this->stopByCommandMatch('ingest_crawled.py');
            $stoppedPids = $stoppedCount > 0 ? $targetPids : [];
        }

        if ($stoppedCount === 0) {
            return DirectIngestActionResult::fromPayload([
                'ok' => false,
                'message' => 'Failed to stop ingest process.',
                'live_ingestions' => $liveBefore,
            ], 500);
        }

        $this->markEntriesStopped($paths->statusPath, $stoppedPids);

        return DirectIngestActionResult::fromPayload([
            'ok' => true,
            'stopped_count' => $stoppedCount,
            'stopped_pids' => $stoppedPids,
            'live_ingestions' => $liveBefore,
        ]);
    }

    private function targetPids(array $data, array $liveBefore): array
    {
        if (! empty($data['pids']) && is_array($data['pids'])) {
            return array_values(array_filter($data['pids'], 'is_numeric'));
        }
        if (! empty($data['pid'])) {
            return [(int) $data['pid']];
        }

        return array_values(array_filter(array_map(static function ($item) {
            return isset($item['pid']) ? (int) $item['pid'] : null;
        }, $liveBefore)));
    }

    private function markEntriesStopped(string $statusPath, array $stoppedPids): void
    {
        $entries = $this->statuses->load($statusPath);
        $now = $this->clock->now()->format(\DateTimeInterface::ATOM);
        foreach ($entries as &$entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pid = isset($entry['pid']) ? (int) $entry['pid'] : null;
            if (! $pid || ! in_array($pid, $stoppedPids, true)) {
                continue;
            }

            $entry['status'] = 'stopped';
            $entry['updated_at'] = $now;
            $pipelineJobId = (string) ($entry['pipeline_job_id'] ?? '');
            if ($pipelineJobId !== '') {
                $this->pipelineState->partialStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
                    'dataset_path' => $entry['path'] ?? null,
                    'counts' => [
                        'total' => 0,
                        'received' => 0,
                        'processing' => 0,
                        'completed' => 0,
                        'failed' => 0,
                        'stopped' => 1,
                    ],
                    'metadata' => [
                        'mode' => 'direct-ui',
                        'message' => 'Ingest process stopped by request.',
                        'pid' => $pid,
                    ],
                ]);
            }
        }
        unset($entry);

        $this->statuses->save($statusPath, $entries);
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        @exec('kill -0 '.$pid, $out, $code);

        return $code === 0;
    }

    private function stopByCommandMatch(string $needle): int
    {
        $count = 0;
        foreach (glob('/proc/[0-9]*/cmdline') as $cmdlinePath) {
            $cmdline = @file_get_contents($cmdlinePath);
            if (! $cmdline) {
                continue;
            }

            $cmdline = str_replace("\0", ' ', $cmdline);
            if (stripos($cmdline, $needle) === false) {
                continue;
            }
            if (preg_match('~/proc/(\\d+)/cmdline$~', $cmdlinePath, $m)) {
                $pid = (int) $m[1];
                if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15)) {
                    $count += 1;
                }
            }
        }

        return $count;
    }
}
