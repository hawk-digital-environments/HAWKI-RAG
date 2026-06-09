<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Output\OutputInterface;

class CrawledFileConversionService
{
    public function __construct(
        private readonly CrawledFileDiscovery $discovery,
        private readonly ExistingConversionPolicy $existingConversionPolicy,
        private readonly ConversionReportWriter $reports,
        private readonly ConvertedOutputWriter $outputs,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock = new Clock,
    ) {
    }

    public function run(
        Command $command,
        OutputInterface $output,
        bool $interactive,
        DocumentConverter $converter,
        PipelineDataValidator $validator,
        PipelineStateService $state,
        PipelineStageLogger $logger,
    ): int {
        return (new CrawledFileConversionWorkflow(
            $this->discovery,
            $this->existingConversionPolicy,
            $this->reports,
            $this->outputs,
            $this->config,
            $this->clock,
        ))->run(
            new ConsoleWorkflowIO($command, $output, $interactive),
            $converter,
            $validator,
            $state,
            $logger,
        );
    }
}
