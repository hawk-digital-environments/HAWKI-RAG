<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use App\Services\Pipeline\Status\PipelineStatusService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class PipelineProofService
{
    public function __construct(
        private readonly PipelineProofMarkdownRenderer $markdown,
        private readonly PipelineProofLogCollector $logs,
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock = new Clock,
    ) {
    }

    public function run(Command $command, PipelineProofRepository $proofs, PipelineStatusService $statuses): int
    {
        return (new PipelineProofWorkflow($this->markdown, $this->logs, $this->files, $this->config, $this->clock, $statuses))
            ->run(new ConsoleWorkflowIO($command), $proofs);
    }
}
