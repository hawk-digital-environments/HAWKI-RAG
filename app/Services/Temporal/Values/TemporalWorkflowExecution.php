<?php

declare(strict_types=1);

namespace App\Services\Temporal\Values;

readonly class TemporalWorkflowExecution
{
    public function __construct(
        public string $workflowId,
        public ?string $runId = null,
        public ?string $scheduleId = null,
    ) {
    }
}
