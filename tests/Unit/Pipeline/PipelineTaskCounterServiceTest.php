<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Tasks\PipelineTaskCounterService;
use Tests\TestCase;

class PipelineTaskCounterServiceTest extends TestCase
{
    public function test_it_builds_default_counters(): void
    {
        $counters = app(PipelineTaskCounterService::class)->defaults();

        $this->assertSame(0, $counters['queued']);
        $this->assertSame(0, $counters['jobs_total']);
        $this->assertSame(0, $counters['convert_jobs']);
        $this->assertSame(0, $counters['ingest_jobs']);
    }

    public function test_it_calculates_task_counters_from_jobs(): void
    {
        $jobs = collect([
            new PipelineJob([
                'job_type' => PipelineJob::TYPE_CONVERT,
                'status' => PipelineJob::STATUS_COMPLETED,
            ]),
            new PipelineJob([
                'job_type' => PipelineJob::TYPE_CONVERT,
                'status' => PipelineJob::STATUS_SKIPPED,
            ]),
            new PipelineJob([
                'job_type' => PipelineJob::TYPE_INGEST,
                'status' => PipelineJob::STATUS_RUNNING,
            ]),
            new PipelineJob([
                'job_type' => PipelineJob::TYPE_INGEST,
                'status' => PipelineJob::STATUS_FAILED,
            ]),
        ]);

        $counters = app(PipelineTaskCounterService::class)->forJobs($jobs);

        $this->assertSame(4, $counters['jobs_total']);
        $this->assertSame(1, $counters['jobs_active']);
        $this->assertSame(1, $counters['jobs_running']);
        $this->assertSame(1, $counters['jobs_completed']);
        $this->assertSame(1, $counters['jobs_failed']);
        $this->assertSame(1, $counters['jobs_skipped']);
        $this->assertSame(2, $counters['convert_jobs']);
        $this->assertSame(2, $counters['ingest_jobs']);
        $this->assertSame(2, $counters['files_found']);
        $this->assertSame(1, $counters['converted']);
        $this->assertSame(0, $counters['ingested']);
        $this->assertSame(1, $counters['failed']);
        $this->assertSame(1, $counters['skipped']);
    }

    public function test_it_calculates_convert_and_ingest_counters_from_temporal_stage_rows(): void
    {
        $job = new PipelineJob([
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
        $job->setRelation('stages', collect([
            new PipelineStageState([
                'stage' => 'convert',
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => ['total' => 21, 'processed' => 21, 'convertedFiles' => 21],
            ]),
            new PipelineStageState([
                'stage' => 'ingest',
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => ['total' => 21, 'processed' => 21],
            ]),
        ]));

        $counters = app(PipelineTaskCounterService::class)->forJobs(collect([$job]));

        $this->assertSame(1, $counters['jobs_total']);
        $this->assertSame(1, $counters['jobs_completed']);
        $this->assertSame(1, $counters['convert_jobs']);
        $this->assertSame(1, $counters['ingest_jobs']);
        $this->assertSame(21, $counters['files_found']);
        $this->assertSame(21, $counters['converted']);
        $this->assertSame(1, $counters['ingested']);
    }
}
