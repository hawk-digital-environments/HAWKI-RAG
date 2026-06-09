<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use Illuminate\Console\Command;

class PipelineProofService
{
    public function __construct(private readonly PipelineProofWorkflow $workflow)
    {
    }

    public function run(Command $command, PipelineProofRepository $proofs): int
    {
        return $this->workflow->run(new ConsoleWorkflowIO($command), $proofs);
    }
}
