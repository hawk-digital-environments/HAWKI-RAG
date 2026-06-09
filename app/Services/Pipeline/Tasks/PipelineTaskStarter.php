<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineScrapeHistoryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskStarter
{
    public function __construct(
        private DatasetService $datasets,
        private PipelineTaskCounterService $counters,
        private PipelineTaskEventPayloadService $eventPayloads,
        private PipelineTaskInputNormalizer $input,
        private PipelineTaskMetadataService $metadata,
        private PipelineTaskSourceResolver $sources,
        private PipelineTaskRepository $taskRepository,
        private PipelineJobCreationRepository $jobCreation,
        private PipelineScrapeHistoryRepository $scrapeHistoryRepository,
        private PipelineTransactionRepository $transactions,
        private PipelineTaskEventPublisher $publisher,
        private PipelineTaskStatusRefresher $refresher,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function start(array $input): PipelineTask
    {
        $task = $this->transactions->run(function () use ($input): PipelineTask {
            $dataset = $this->datasets->ensure($input);
            $task = $this->taskRepository->createRunningTask(
                $this->input->taskId($input),
                $dataset,
                $this->now(),
                $this->counters->defaults(),
                [
                    'request' => $input,
                    'orchestration' => 'laravel',
                    'rabbitmq' => ['event_bus' => true],
                    'dataset' => $this->metadata->dataset($dataset),
                ],
            );

            foreach ($this->sources->resolve($input) as $url) {
                $this->createScrapeJob($task, $url);
            }

            return $this->refresher->recalculate($task);
        });

        return $task->refresh();
    }

    private function createScrapeJob(PipelineTask $task, string $url): PipelineJob
    {
        $contentHash = hash('sha256', $url);
        $alreadyScraped = $this->scrapeHistoryRepository->hasCompletedScrape($url, $contentHash);
        $status = $alreadyScraped ? PipelineJob::STATUS_SKIPPED : PipelineJob::STATUS_QUEUED;
        $now = $this->now();

        $job = $this->jobCreation->createScrapeJob(
            ($alreadyScraped ? 'skipped_' : 'scrape_').substr(hash('sha256', $task->task_id.'|'.$url), 0, 24),
            $task,
            $url,
            $contentHash,
            $status,
            $now,
            $alreadyScraped ? $now : null,
            array_merge($this->metadata->taskJob($task), [
                'reason' => $alreadyScraped ? 'URL was already scraped by Laravel.' : 'Queued for scraper worker through RabbitMQ.',
                'dataset_id' => $task->dataset_id,
            ]),
        );

        if (! $alreadyScraped) {
            $this->publisher->publish(
                PipelineEvent::SCRAPE_REQUESTED,
                $this->eventPayloads->forJob($task, $job, PipelineEvent::SCRAPE_REQUESTED),
            );
        }

        return $job;
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
