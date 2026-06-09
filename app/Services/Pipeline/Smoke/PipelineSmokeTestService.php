<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Services\Dataset\DatasetService;
use App\Services\Document\DocumentRepository;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineSmokeTestService
{
    public function __construct(
        private PipelineSmokeTestWorkflow $workflow,
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
        ActivePipelineJobsQuery $jobs,
        PipelineEventRecordRepository $eventRecords,
    ): int {
        return $this->workflow->run(
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
