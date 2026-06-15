<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Log;
use Throwable;

class PipelineLogger
{
    public const EVENT = 'pipeline.stage';
    public const CHANNEL = 'communication';

    public static function started(string $stage, array $context = []): void
    {
        self::log('info', $stage, 'started', $context);
    }

    public static function success(string $stage, array $context = []): void
    {
        self::log('info', $stage, 'success', $context);
    }

    public static function skipped(string $stage, array $context = []): void
    {
        self::log('info', $stage, 'skipped', $context);
    }

    public static function partial(string $stage, array $context = []): void
    {
        self::log('warning', $stage, 'partial', $context);
    }

    public static function failed(string $stage, array $context = []): void
    {
        self::log('error', $stage, 'failed', $context);
    }

    public static function validationFailed(string $stage, array $context = []): void
    {
        self::log('warning', $stage, 'validation_failed', $context);
    }

    public static function log(string $level, string $stage, string $status, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $base = [
            'event' => self::EVENT,
            'stage' => $stage,
            'status' => $status,
            'job_id' => self::nullableString($context['job_id'] ?? null),
            'doc_id' => self::nullableString($context['doc_id'] ?? null),
            'error_message' => self::nullableString($context['error_message'] ?? null),
        ];

        if ($exception instanceof Throwable) {
            $base['error_type'] = $exception::class;
            $base['error_message'] = $base['error_message'] ?: $exception->getMessage();
        }

        Log::channel(self::CHANNEL)->log($level, self::EVENT, array_merge($base, $context));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
