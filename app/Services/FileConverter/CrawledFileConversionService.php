<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class CrawledFileConversionService
{
    public function __construct(
        private readonly CrawledFileDiscovery $discovery,
        private readonly ExistingConversionPolicy $existingConversionPolicy,
        private readonly ConversionReportWriter $reports,
        private readonly SingleFileConversionProcessor $fileProcessor,
        private readonly ConversionProgressTracker $progress,
        private readonly ConfigRepository $config,
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
            $this->fileProcessor,
            $this->progress,
            $this->config,
        ))->run(
            new ConsoleWorkflowIO($command, $output, $interactive),
            $converter,
            $validator,
            $state,
            $logger,
        );
    }
}
