<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskCounterService
{
    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return array<string, int>
     */
    public function forJobs(Collection $jobs): array
    {
        $byStatus = $jobs->countBy('status');
        $running = $this->runningCount($jobs);

        $counters = [
            'queued' => (int) ($byStatus[PipelineJob::STATUS_QUEUED] ?? 0),
            'scraped' => $jobs
                ->where('job_type', PipelineJob::TYPE_SCRAPE)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'files_found' => $jobs
                ->where('job_type', PipelineJob::TYPE_CONVERT)
                ->count(),
            'converted' => $jobs
                ->where('job_type', PipelineJob::TYPE_CONVERT)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'ingested' => $jobs
                ->where('job_type', PipelineJob::TYPE_INGEST)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'skipped' => (int) ($byStatus[PipelineJob::STATUS_SKIPPED] ?? 0),
            'failed' => (int) ($byStatus[PipelineJob::STATUS_FAILED] ?? 0),
        ];

        return array_merge($counters, [
            'jobs_total' => $jobs->count(),
            'jobs_active' => $counters['queued'] + $running,
            'jobs_queued' => $counters['queued'],
            'jobs_pending' => $counters['queued'],
            'jobs_running' => $running,
            'jobs_completed' => (int) ($byStatus[PipelineJob::STATUS_COMPLETED] ?? 0),
            'jobs_failed' => $counters['failed'],
            'jobs_skipped' => $counters['skipped'],
            'scrape_jobs' => $jobs->where('job_type', PipelineJob::TYPE_SCRAPE)->count(),
            'convert_jobs' => $jobs->where('job_type', PipelineJob::TYPE_CONVERT)->count(),
            'ingest_jobs' => $jobs->where('job_type', PipelineJob::TYPE_INGEST)->count(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function defaults(): array
    {
        return [
            'queued' => 0,
            'scraped' => 0,
            'files_found' => 0,
            'converted' => 0,
            'ingested' => 0,
            'skipped' => 0,
            'failed' => 0,
            'jobs_total' => 0,
            'jobs_active' => 0,
            'jobs_queued' => 0,
            'jobs_pending' => 0,
            'jobs_running' => 0,
            'jobs_completed' => 0,
            'jobs_failed' => 0,
            'jobs_skipped' => 0,
            'scrape_jobs' => 0,
            'convert_jobs' => 0,
            'ingest_jobs' => 0,
        ];
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     */
    public function runningCount(Collection $jobs): int
    {
        return $jobs->where('status', PipelineJob::STATUS_RUNNING)->count();
    }
}
