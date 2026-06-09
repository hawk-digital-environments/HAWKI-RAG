<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;

#[Singleton]
readonly class PipelineStageLogger
{
    public const EVENT = 'pipeline.stage';

    public const CHANNEL = 'communication';

    public function started(string $stage, array $context = []): void
    {
        $this->log('info', $stage, 'started', $context);
    }

    public function success(string $stage, array $context = []): void
    {
        $this->log('info', $stage, 'success', $context);
    }

    public function skipped(string $stage, array $context = []): void
    {
        $this->log('info', $stage, 'skipped', $context);
    }

    public function partial(string $stage, array $context = []): void
    {
        $this->log('warning', $stage, 'partial', $context);
    }

    public function failed(string $stage, array $context = []): void
    {
        $this->log('error', $stage, 'failed', $context);
    }

    public function validationFailed(string $stage, array $context = []): void
    {
        $this->log('warning', $stage, 'validation_failed', $context);
    }

    public function log(string $level, string $stage, string $status, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $base = [
            'event' => self::EVENT,
            'stage' => $stage,
            'status' => $status,
            'job_id' => $this->nullableString($context['job_id'] ?? null),
            'doc_id' => $this->nullableString($context['doc_id'] ?? null),
            'error_message' => $this->nullableString($context['error_message'] ?? null),
        ];

        if ($exception instanceof \Throwable) {
            $base['error_type'] = $exception::class;
            $base['error_message'] = $base['error_message'] ?: $exception->getMessage();
        }

        Log::channel(self::CHANNEL)->log($level, self::EVENT, array_merge($base, $context));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
