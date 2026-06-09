<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Recovery\PipelineRecoveryInputNormalizer;
use Tests\TestCase;

class PipelineRecoveryInputNormalizerTest extends TestCase
{
    public function test_it_normalizes_failed_job_filters(): void
    {
        $filters = app(PipelineRecoveryInputNormalizer::class)->filters([
            'limit' => 800,
            'taskId' => ' task-recovery ',
            'dataset_id' => ' dataset-recovery ',
        ]);

        $this->assertSame(500, $filters['limit']);
        $this->assertSame('task-recovery', $filters['task_id']);
        $this->assertSame('dataset-recovery', $filters['dataset_id']);

        $filters = app(PipelineRecoveryInputNormalizer::class)->filters([
            'limit' => -5,
            'task_id' => ' ',
            'datasetId' => ['invalid'],
        ]);

        $this->assertSame(1, $filters['limit']);
        $this->assertNull($filters['task_id']);
        $this->assertNull($filters['dataset_id']);
    }

    public function test_it_normalizes_selected_job_ids(): void
    {
        $jobIds = app(PipelineRecoveryInputNormalizer::class)->jobIds([
            ' convert-a ',
            '',
            'convert-a',
            'convert-b',
            ['invalid'],
            123,
        ]);

        $this->assertSame(['convert-a', 'convert-b', '123'], $jobIds);
    }
}
