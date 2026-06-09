<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dataset\DatasetService;
use App\Services\Document\DocumentRepository;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Smoke\PipelineSmokeTestService;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class PipelineSmokeTestCommand extends Command
{
    protected $signature = 'pipeline:smoke-test
        {--dataset=smoke-demo : Dataset identifier for the smoke run}
        {--graph=auto : true, false, or auto; auto uses RAG_INGEST_GRAPH}
        {--url= : Optional source URL label for the smoke run}
        {--timeout=15 : HTTP timeout in seconds for Qdrant and Neo4j checks}
        {--keep-files=false : Keep generated fixture files after the run}';

    protected $description = 'Run an end-to-end MVP pipeline smoke test for scrape, convert, ingest, Qdrant, and optional Neo4j.';

    public function handle(
        PipelineSmokeTestService $smoke,
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
        return $smoke->run(
            $this,
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
