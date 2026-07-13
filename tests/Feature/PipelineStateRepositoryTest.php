<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineStateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_repositories_ensure_jobs_and_upsert_stage_states(): void
    {
        $jobs = app(PipelineJobCreationRepository::class);
        $stages = app(PipelineStageStateRepository::class);
        $startedAt = Carbon::parse('2026-06-08 16:00:00');
        $transitionedAt = Carbon::parse('2026-06-08 16:01:00');

        $job = $jobs->ensureStateJob(
            'state-repository-job',
            [
                'source_url' => 'https://example.test/state-repository',
                'dataset_path' => '/app/shared/state-repository',
            ],
            $startedAt,
            PipelineJob::STATUS_PENDING,
        );

        $stage = $stages->upsertForJob(
            $job,
            'state-repository-job',
            PipelineStateService::STAGE_SCRAPE,
            [
                'status' => PipelineJob::STATUS_RUNNING,
                'counts' => ['totalPages' => 2],
            ],
            [PipelineJob::STATUS_RUNNING, 'processing', 'received'],
            $transitionedAt,
        );

        $this->assertSame('state-repository-job', $job->job_id);
        $this->assertSame(PipelineJob::STATUS_PENDING, $job->status);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $stage->status);
        $this->assertTrue($transitionedAt->equalTo($stage->started_at));
        $this->assertTrue($transitionedAt->equalTo($stage->last_transition_at));
        $this->assertSame(PipelineJob::STATUS_RUNNING, $stages->statusValue('state-repository-job', PipelineStateService::STAGE_SCRAPE));
        $this->assertSame($stage->id, $stages->findForJobStage('state-repository-job', PipelineStateService::STAGE_SCRAPE)?->id);
        $this->assertCount(1, $stages->forPipelineJob($job));
    }

    public function test_state_service_updates_stages_and_rolls_up_job_status(): void
    {
        $state = app(PipelineStateService::class);

        $state->startStage('state-service-job', PipelineStateService::STAGE_SCRAPE, [
            'source_url' => 'https://example.test/state-service',
            'counts' => ['totalPages' => 2],
            'label' => 'State Service Job',
        ]);

        $running = $state->status('state-service-job');
        $this->assertSame(PipelineJob::STATUS_RUNNING, $running['status']);
        $this->assertSame(PipelineStateService::STAGE_SCRAPE, $running['current_stage']);
        $this->assertSame(2, $running['document_counts']['total']);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $running['stages'][PipelineStateService::STAGE_SCRAPE]['status']);

        $state->completeStage('state-service-job', PipelineStateService::STAGE_SCRAPE, [
            'counts' => [
                'totalPages' => 2,
                'pagesCrawled' => 2,
            ],
        ]);

        $completed = $state->status('state-service-job');
        $this->assertSame(PipelineJob::STATUS_PARTIAL, $completed['status']);
        $this->assertSame(2, $completed['document_counts']['processed']);
        $this->assertSame(PipelineJob::STATUS_COMPLETED, $completed['stages'][PipelineStateService::STAGE_SCRAPE]['status']);
        $this->assertTrue($state->isStageCompleted('state-service-job', PipelineStateService::STAGE_SCRAPE));
    }

    public function test_state_service_increments_stage_counts(): void
    {
        $state = app(PipelineStateService::class);

        $state->startStage('state-count-job', PipelineStateService::STAGE_INGEST, [
            'counts' => [
                'completed' => 1,
                'failed' => 1,
            ],
        ]);

        $stage = $state->incrementStageCounts('state-count-job', PipelineStateService::STAGE_INGEST, [
            'completed' => 2,
            'failed' => -1,
            'skipped' => 3,
        ]);

        $this->assertSame(3, $stage->counts['completed']);
        $this->assertSame(0, $stage->counts['failed']);
        $this->assertSame(3, $stage->counts['skipped']);

        $status = $state->status('state-count-job');
        $this->assertSame(3, $status['document_counts']['processed']);
        $this->assertSame(3, $status['document_counts']['skipped']);
    }

    public function test_state_service_claims_stages_with_required_completed_stages(): void
    {
        $state = app(PipelineStateService::class);

        $this->assertNull($state->claimStage(
            'state-claim-job',
            PipelineStateService::STAGE_CONVERT,
            [],
            [PipelineStateService::STAGE_SCRAPE],
        ));

        $state->completeStage('state-claim-job', PipelineStateService::STAGE_SCRAPE);

        $claim = $state->claimStage(
            'state-claim-job',
            PipelineStateService::STAGE_CONVERT,
            ['metadata' => ['claimed_by' => 'test']],
            [PipelineStateService::STAGE_SCRAPE],
        );

        $this->assertNotNull($claim);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $claim->status);
        $this->assertSame('test', $claim->metadata['claimed_by']);
        $this->assertTrue($state->isStageClaimedOrDone('state-claim-job', PipelineStateService::STAGE_CONVERT));

        $this->assertNull($state->claimStage('state-claim-job', PipelineStateService::STAGE_CONVERT));

        $forced = $state->claimStage(
            'state-claim-job',
            PipelineStateService::STAGE_CONVERT,
            ['metadata' => ['claimed_by' => 'forced']],
            [],
            true,
        );

        $this->assertNotNull($forced);
        $this->assertSame('forced', $forced->metadata['claimed_by']);
    }
}
