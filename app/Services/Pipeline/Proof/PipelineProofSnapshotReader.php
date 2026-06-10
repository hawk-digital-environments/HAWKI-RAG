<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Status\PipelineStatusService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineProofSnapshotReader
{
    public function __construct(
        private PipelineStatusService $statuses,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $jobId, string $reason): array
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

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
