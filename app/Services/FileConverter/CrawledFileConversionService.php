<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;
use Symfony\Component\Console\Output\OutputInterface;

#[Singleton]
readonly class CrawledFileConversionService
{
    public function __construct(
        private CrawledFileConversionWorkflow $workflow,
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
        return $this->workflow->run(
            new ConsoleWorkflowIO($command, $output, $interactive),
            $converter,
            $validator,
            $state,
            $logger,
        );
    }
}
