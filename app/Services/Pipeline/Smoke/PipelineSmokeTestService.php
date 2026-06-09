<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Services\Datasets\DatasetService;
use App\Services\Documents\DocumentRepository;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class PipelineSmokeTestService
{
    public function __construct(
        private readonly PipelineSmokeFixtureFactory $fixtures,
        private readonly PipelineSmokeExternalVerifier $externalVerifier,
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock = new Clock,
    ) {
    }

    public function run(
        Command $command,
        PipelineTaskService $tasks,
        PipelineEventBus $events,
        PipelineEventStateService $state,
        ConverterEventHandler $converter,
        IngestionEventHandler $ingestion,
        DatasetService $datasets,
        DocumentRepository $documents,
        PipelineJobRepository $jobs,
        PipelineEventRecordRepository $eventRecords,
    ): int {
        return (new PipelineSmokeTestWorkflow(
            $this->fixtures,
            $this->externalVerifier,
            $this->files,
            $this->config,
            $this->clock,
        ))->run(
            new ConsoleWorkflowIO($command),
            $tasks,
            $events,
            $state,
            $converter,
            $ingestion,
            $datasets,
            $documents,
            $jobs,
            $eventRecords,
        );
    }
}
