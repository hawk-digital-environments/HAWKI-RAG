<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Status\PipelineStageStatusMerger;
use Tests\TestCase;

class PipelineStageStatusMergerTest extends TestCase
{
    public function test_it_selects_current_stage_and_overall_status(): void
    {
        $merger = app(PipelineStageStatusMerger::class);
        $scrape = ['status' => 'completed'];
        $convert = ['status' => 'running'];
        $ingest = ['status' => 'pending'];

        $this->assertSame('convert', $merger->currentStage($scrape, $convert, $ingest));
        $this->assertSame('running', $merger->overallStatus($scrape, $convert, $ingest));

        $this->assertSame(
            'completed',
            $merger->overallStatus(
                ['status' => 'completed'],
                ['status' => 'completed'],
                ['status' => 'completed'],
            ),
        );
    }

    public function test_not_tracked_ingest_is_not_treated_as_an_active_stage(): void
    {
        $merger = app(PipelineStageStatusMerger::class);

        $this->assertSame(
            'convert',
            $merger->currentStage(
                ['status' => 'completed'],
                ['status' => 'running'],
                ['status' => 'not_tracked'],
            ),
        );

        $this->assertSame(
            'ingest',
            $merger->currentStage(
                ['status' => 'completed'],
                ['status' => 'completed'],
                ['status' => 'not_tracked'],
            ),
        );
    }

    public function test_it_merges_tracked_stage_details_without_losing_status(): void
    {
        $merger = app(PipelineStageStatusMerger::class);

        $merged = $merger->mergeTrackedStage(
            [
                'status' => 'unknown',
                'counts' => ['processed' => 2],
                'errors' => ['computed error'],
                'warnings' => [],
            ],
            [
                'status' => 'running',
                'counts' => ['total' => 3],
                'errors' => ['tracked error'],
                'warnings' => ['tracked warning'],
            ],
        );

        $this->assertSame('running', $merged['status']);
        $this->assertSame(['total' => 3, 'processed' => 2], $merged['counts']);
        $this->assertSame(['tracked error', 'computed error'], $merged['errors']);
        $this->assertSame(['tracked warning'], $merged['warnings']);
    }
}
