<?php

namespace App\Console\Commands;

use App\Models\ScheduledCrawlJob;
use App\Models\ScheduledCrawlJobRun;
use App\Services\ScheduledCrawl\MakePipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RagRunScheduledCrawls extends Command
{
    protected $signature = 'rag:run-scheduled-crawls';

    protected $description = 'Run due scheduled crawls by calling Make pipeline commands with prechecks';

    public function __construct(
        private MakePipelineService $pipelineService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dueJobs = ScheduledCrawlJob::query()
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('next_run_at')
            ->get();

        if ($dueJobs->isEmpty()) {
            $this->info('No due scheduled crawl jobs.');
            return self::SUCCESS;
        }

        foreach ($dueJobs as $job) {
            $claimed = $this->claimJobRun($job->id);
            if ($claimed === null) {
                $this->line("Skipping job {$job->id}: already running.");
                continue;
            }

            /** @var ScheduledCrawlJob $lockedJob */
            $lockedJob = $claimed['job'];
            /** @var ScheduledCrawlJobRun $run */
            $run = $claimed['run'];

            $this->processRun($lockedJob, $run);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{job: ScheduledCrawlJob, run: ScheduledCrawlJobRun}|null
     */
    private function claimJobRun(int $jobId): ?array
    {
        return DB::transaction(function () use ($jobId) {
            $job = ScheduledCrawlJob::query()->lockForUpdate()->find($jobId);
            if ($job === null || !$job->active) {
                return null;
            }

            $hasRunning = ScheduledCrawlJobRun::query()
                ->where('scheduled_crawl_job_id', $job->id)
                ->whereIn('status', [
                    ScheduledCrawlJobRun::STATUS_PRECHECKING,
                    ScheduledCrawlJobRun::STATUS_RUNNING_SCRAPER,
                    ScheduledCrawlJobRun::STATUS_RUNNING_INGEST,
                ])
                ->exists();

            if ($hasRunning) {
                return null;
            }

            $jobIdValue = $this->buildJobId($job);
            $pipelineMode = $this->pipelineService->normalizePipelineMode((string) config('scheduler_pipeline.pipeline_mode', 'make-sync'));

            $run = ScheduledCrawlJobRun::create([
                'scheduled_crawl_job_id' => $job->id,
                'job_id' => $jobIdValue,
                'url' => $job->url,
                'period' => $job->period,
                'crawled_root' => $job->crawled_root,
                'collection' => $job->collection,
                'graph_enabled' => $job->graph_enabled,
                'pipeline_mode' => $pipelineMode,
                'scraper_command' => '',
                'ingest_command' => null,
                'status' => ScheduledCrawlJobRun::STATUS_PENDING,
                'started_at' => now(),
            ]);

            return ['job' => $job, 'run' => $run];
        });
    }

    private function processRun(ScheduledCrawlJob $job, ScheduledCrawlJobRun $run): void
    {
        $payload = [
            'job_id' => $run->job_id,
            'url' => $job->url,
            'crawled_root' => $job->crawled_root,
            'sitemap_pages' => $job->sitemap_pages,
            'max_pages' => $job->max_pages ?? '',
            'rescrape_failed' => $job->rescrape_failed,
            'skip_images' => $job->skip_images,
            'graph_enabled' => $job->graph_enabled,
            'pipeline_mode' => $run->pipeline_mode,
        ];

        try {
            $run->update([
                'status' => ScheduledCrawlJobRun::STATUS_PRECHECKING,
            ]);

            $precheck = $this->pipelineService->runPrecheck($payload);
            if (!$precheck['ok']) {
                $message = implode(PHP_EOL, $precheck['errors']);

                $run->update([
                    'status' => ScheduledCrawlJobRun::STATUS_FAILED_PRECHECK,
                    'error_message' => $message,
                    'finished_at' => now(),
                ]);

                $this->markNextRun($job);
                $this->error("Job {$job->id} failed precheck: {$message}");
                return;
            }

            $run->update([
                'status' => ScheduledCrawlJobRun::STATUS_RUNNING_SCRAPER,
            ]);

            $scraper = $this->pipelineService->runScraper($payload);
            $run->update([
                'scraper_command' => $scraper['command'],
                'exit_code' => $scraper['exit_code'],
                'stdout' => $scraper['stdout'],
                'stderr' => $scraper['stderr'],
            ]);

            if (!$scraper['successful']) {
                $run->update([
                    'status' => ScheduledCrawlJobRun::STATUS_SCRAPER_FAILED,
                    'error_message' => trim($scraper['stderr']) !== '' ? trim($scraper['stderr']) : 'Scraper make command failed',
                    'finished_at' => now(),
                ]);

                $this->markNextRun($job);
                $this->error("Job {$job->id} scraper failed.");
                return;
            }

            if ($run->pipeline_mode === 'rabbitmq-event') {
                $run->update([
                    'status' => ScheduledCrawlJobRun::STATUS_DISPATCHED,
                    'finished_at' => now(),
                ]);

                $this->markNextRun($job);
                $this->info("Job {$job->id} dispatched via scraper Make command (rabbitmq-event mode).");
                return;
            }

            $run->update([
                'status' => ScheduledCrawlJobRun::STATUS_RUNNING_INGEST,
            ]);

            $ingest = $this->pipelineService->runIngest($payload);
            $run->update([
                'ingest_command' => $ingest['command'],
                'exit_code' => $ingest['exit_code'],
                'stdout' => trim((string) $run->stdout . PHP_EOL . PHP_EOL . '[ingest]' . PHP_EOL . $ingest['stdout']),
                'stderr' => trim((string) $run->stderr . PHP_EOL . PHP_EOL . '[ingest]' . PHP_EOL . $ingest['stderr']),
            ]);

            if (!$ingest['successful']) {
                $run->update([
                    'status' => ScheduledCrawlJobRun::STATUS_INGEST_FAILED,
                    'error_message' => trim($ingest['stderr']) !== '' ? trim($ingest['stderr']) : 'Ingest make command failed',
                    'finished_at' => now(),
                ]);

                $this->markNextRun($job);
                $this->error("Job {$job->id} ingest failed.");
                return;
            }

            $run->update([
                'status' => ScheduledCrawlJobRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ]);

            $this->markNextRun($job);
            $this->info("Job {$job->id} completed.");
        } catch (Throwable $e) {
            $run->update([
                'status' => ScheduledCrawlJobRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->markNextRun($job);
            $this->error("Job {$job->id} failed with exception: {$e->getMessage()}");
        }
    }

    private function buildJobId(ScheduledCrawlJob $job): string
    {
        if (!empty($job->job_id)) {
            return (string) $job->job_id;
        }

        return 'job_date_' . now()->format('Y_m_d');
    }

    private function markNextRun(ScheduledCrawlJob $job): void
    {
        $now = now();
        $job->update([
            'last_run_at' => $now,
            'next_run_at' => $job->computeNextRunAt($now),
        ]);
    }
}
