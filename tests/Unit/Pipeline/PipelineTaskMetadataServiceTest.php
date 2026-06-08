<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineTaskMetadataService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineTaskMetadataServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_shapes_dataset_metadata(): void
    {
        $metadata = app(PipelineTaskMetadataService::class)->dataset(new Dataset([
            'dataset_id' => 'dataset-meta',
            'qdrant_collection' => 'hawki_dataset_meta',
            'neo4j_namespace' => 'hawki_dataset_meta',
        ]));

        $this->assertSame([
            'dataset_id' => 'dataset-meta',
            'qdrant_collection' => 'hawki_dataset_meta',
            'neo4j_namespace' => 'hawki_dataset_meta',
        ], $metadata);
    }

    public function test_it_builds_task_job_metadata_from_task_request_metadata(): void
    {
        $task = new PipelineTask([
            'metadata' => [
                'request' => [
                    'metadata' => [
                        'source' => 'unit-test',
                        'max_pages' => 5,
                    ],
                ],
                'dataset' => [
                    'dataset_id' => 'dataset-meta',
                ],
            ],
        ]);

        $metadata = app(PipelineTaskMetadataService::class)->taskJob($task);

        $this->assertSame('unit-test', $metadata['source']);
        $this->assertSame(5, $metadata['max_pages']);
        $this->assertSame(['dataset_id' => 'dataset-meta'], $metadata['dataset']);
    }

    public function test_it_appends_task_metadata_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00'));

        $task = new PipelineTask([
            'metadata' => [
                'events' => [
                    [
                        'event' => 'task_started',
                        'at' => '2026-06-08T11:00:00+00:00',
                    ],
                ],
            ],
        ]);

        $metadata = app(PipelineTaskMetadataService::class)->appendEvent($task, 'failed_jobs_retried');

        $this->assertCount(2, $metadata['events']);
        $this->assertSame('failed_jobs_retried', $metadata['events'][1]['event']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $metadata['events'][1]['at']);
    }
}
