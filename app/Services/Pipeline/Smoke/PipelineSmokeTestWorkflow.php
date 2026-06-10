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
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineSmokeTestWorkflow
{
    public function __construct(
        private PipelineSmokeBootstrapStage $bootstrap,
        private PipelineSmokeConversionStage $conversion,
        private PipelineSmokeIngestionStage $ingestionStage,
        private PipelineSmokeExternalWriteStage $externalWrites,
        private PipelineSmokeRunContextFactory $runContexts,
        private PipelineSmokeResultReporter $results,
        private Filesystem $files,
    ) {
    }

    public function run(
        ConsoleWorkflowIO $io,
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
        $runner = new PipelineSmokeStageRunner($io);
        $context = $this->runContexts->fromIO($io);

        $io->line('HAWKI RAG MVP smoke test');
        $io->line("Task ID: {$context->taskId}");
        $io->line("Dataset: {$context->datasetId}");
        $io->line('Graph mode: '.($context->graph ? 'true' : 'false'));
        $io->newLine();

        try {
            $bootstrap = $this->bootstrap->run(
                $runner,
                $context,
                $tasks,
                $events,
                $state,
                $jobs,
                $eventRecords,
            );
            $conversion = $this->conversion->run(
                $runner,
                $context,
                $bootstrap->task,
                $bootstrap->scrapeJob,
                $bootstrap->fixturePath,
                $converter,
                $jobs,
            );
            $document = $this->ingestionStage->run(
                $runner,
                $context,
                $bootstrap->task,
                $bootstrap->scrapeJob,
                $bootstrap->fixturePath,
                $conversion,
                $ingestion,
                $documents,
            );

            $this->externalWrites->run($runner, $context, $datasets, $bootstrap->task, $document);

            $status = $tasks->show($bootstrap->task->task_id);
            $this->results->printSuccess($io, $runner, $bootstrap->task, $document, $status);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->newLine();
            $runner->printSummary();
            $io->line('Smoke test FAIL: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            if (! $context->keepFiles && $this->files->isDirectory($context->fixtureDir)) {
                $this->files->deleteDirectory($context->fixtureDir);
            }
        }
    }
}
