<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Singleton]
readonly class PipelineTaskInputNormalizer
{
    public function taskId(array $input): string
    {
        $provided = $this->nullableString($input['task_id'] ?? $input['taskId'] ?? null);

        return $provided ?? 'task_'.Str::uuid()->toString();
    }

    public function jobId(array $input): string
    {
        return $this->nullableString($input['job_id'] ?? $input['jobId'] ?? null)
            ?? (string) Str::uuid();
    }

    public function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function jobStatus(mixed $status): string
    {
        $status = $this->nullableString($status) ?? PipelineJob::STATUS_QUEUED;

        return match ($status) {
            'pending' => PipelineJob::STATUS_QUEUED,
            'received',
            'processing' => PipelineJob::STATUS_RUNNING,
            'partial',
            'cancel_requested',
            'cancelled' => PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_QUEUED,
            PipelineJob::STATUS_RUNNING,
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_FAILED => $status,
            default => PipelineJob::STATUS_FAILED,
        };
    }

    public function isTerminalStatus(string $status): bool
    {
        return in_array($status, PipelineJob::TERMINAL_STATUSES, true);
    }

    public function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return Carbon::parse((string) $value);
        }

        return null;
    }
}
