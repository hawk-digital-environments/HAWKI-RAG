<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Exceptions\PipelineSmokeException;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineEventRecordRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineSmokeBootstrapStage
{
    public function __construct(
        private PipelineSmokeFixtureFactory $fixtures,
        private PipelineSmokeRabbitMqPublishingGate $publishingGate,
        private PipelineSmokeEventFactory $eventFactory,
    ) {
    }

    public function run(
        PipelineSmokeStageRunner $runner,
        PipelineSmokeRunContext $context,
        PipelineTaskService $tasks,
        PipelineEventBus $events,
        PipelineEventStateService $state,
        ActivePipelineJobsQuery $jobs,
        PipelineEventRecordRepository $eventRecords,
    ): PipelineSmokeBootstrapResult {
        $fixturePath = $runner->stage('Fixture', function () use ($context): string {
            return $this->fixtures->createDocx($context->fixtureDir, $context->taskId);
        }, fn (string $path): string => "Created DOCX fixture at {$path}.");

        $task = $runner->stage('Task', function () use ($tasks, $context): PipelineTask {
            return $this->publishingGate->withoutPublishing(fn (): PipelineTask => $tasks->start([
                'task_id' => $context->taskId,
                'dataset_id' => $context->datasetId,
                'urls' => [$context->sourceUrl],
                'metadata' => [
                    'source' => 'pipeline-smoke-test',
                    'label' => 'pipeline-smoke',
                    'catalog_task_label' => 'Pipeline smoke test',
                    'max_pages' => 1,
                    'max_concurrency' => 1,
                    'max_rpm' => 30,
                    'skip_images' => true,
                    'discovery_mode' => false,
                    'graph' => $context->graph,
                    'rag_ingest_graph' => $context->graph,
                ],
            ]));
        }, fn (PipelineTask $task): string => "Created task {$task->task_id}.");

        $scrapeJob = $runner->stage('Scrape enqueue', function () use ($jobs, $task): PipelineJob {
            $job = $jobs->firstForTaskAndType($task->task_id, PipelineJob::TYPE_SCRAPE);
            if (! $job) {
                throw PipelineSmokeException::missingScrapeJob();
            }

            if (! in_array($job->status, [PipelineJob::STATUS_QUEUED, PipelineJob::STATUS_SKIPPED], true)) {
                throw PipelineSmokeException::unexpectedScrapeJobStatus($job->job_id, $job->status);
            }

            return $job;
        }, fn (PipelineJob $job): string => "Created scrape job {$job->job_id} with status {$job->status}.");

        $runner->stage('RabbitMQ events', function () use ($eventRecords, $events, $task, $scrapeJob): string {
            if ($this->publishingGate->enabled()) {
                foreach (['scraper', 'scrape_monitor', 'converter', 'ingestion'] as $worker) {
                    $events->declareWorkerTopology($worker);
                }
            }

            $recorded = $eventRecords->existsForJobEvent($task->task_id, $scrapeJob->job_id, PipelineEvent::SCRAPE_REQUESTED);

            if (! $recorded) {
                throw PipelineSmokeException::missingScrapeRequestedEvent();
            }

            return $this->publishingGate->enabled()
                ? 'RabbitMQ topology is reachable and scrape.requested was recorded without sending the synthetic smoke URL to live workers.'
                : 'RabbitMQ publishing is disabled; Laravel event recording was verified.';
        }, fn (string $message): string => $message);

        $pageEvent = $this->eventFactory->pageScraped($task, $scrapeJob, $context->sourceUrl, $fixturePath, $context->graph);
        $runner->stage('Scrape artifact', function () use ($state, $pageEvent): PipelineJob {
            return $state->upsertJob($pageEvent, PipelineJob::STATUS_COMPLETED, [
                'stage' => 'smoke_scrape_artifact_ready',
                'reason' => 'Smoke test generated a local scrape artifact.',
            ]);
        }, fn (PipelineJob $job): string => "Marked scrape job {$job->job_id} completed with local artifact.");

        return new PipelineSmokeBootstrapResult($fixturePath, $task, $scrapeJob);
    }
}
