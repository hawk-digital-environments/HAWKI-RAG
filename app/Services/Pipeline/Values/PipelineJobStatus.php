<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum PipelineJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Partial = 'partial';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Skipped], true);
    }

    public static function terminalValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, [
            self::Completed,
            self::Failed,
            self::Skipped,
        ]);
    }
}
