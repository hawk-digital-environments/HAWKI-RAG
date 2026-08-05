<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum PipelineStageStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
