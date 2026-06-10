<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineUploadRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_repository_creates_upload_task_with_pipeline_defaults(): void
    {
        $dataset = $this->dataset();
        $startedAt = Carbon::parse('2026-06-08 12:00:00');
        $metadata = [
            'request' => [
                'source' => 'pipeline-controller',
                'mode' => 'uploaded_file_convert_ingest',
            ],
            'upload' => [
                'original_filename' => 'sample.pdf',
            ],
        ];

        $task = app(PipelineTaskRepository::class)->createUploadTask(
            'task_upload_repository',
            $dataset,
            $startedAt,
            $metadata,
        );

        $this->assertSame('task_upload_repository', $task->task_id);
        $this->assertSame('repository-dataset', $task->dataset_id);
        $this->assertSame(PipelineTask::STATUS_RUNNING, $task->status);
        $this->assertSame([], $task->counters);
        $this->assertSame($metadata, $task->metadata);
        $this->assertTrue($startedAt->equalTo($task->started_at));

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task_upload_repository',
            'dataset_id' => 'repository-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);
    }

    public function test_job_repository_creates_upload_convert_job_with_pipeline_defaults(): void
    {
        $task = app(PipelineTaskRepository::class)->createUploadTask(
            'task_for_convert_repository',
            $this->dataset(),
            Carbon::parse('2026-06-08 12:00:00'),
            ['request' => ['source' => 'pipeline-controller']],
        );
        $startedAt = Carbon::parse('2026-06-08 12:05:00');
        $storedUpload = PipelineStoredUpload::fromStoredFile(
            'sample.pdf',
            'sample-a1b2c3d4.pdf',
            '/shared/task/sample-a1b2c3d4.pdf',
            'sha256-content-hash',
            'pdf',
        );
        $metadata = [
            'source' => 'pipeline-controller',
            'mode' => 'uploaded_file_convert_ingest',
            'graph' => true,
        ];

        $job = app(PipelineJobCreationRepository::class)->createUploadConvertJob(
            'convert_repository_job',
            $task,
            'upload://sample.pdf',
            $storedUpload,
            $startedAt,
            $metadata,
        );

        $this->assertSame('convert_repository_job', $job->job_id);
        $this->assertSame($task->task_id, $job->task_id);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $job->job_type);
        $this->assertSame(PipelineJob::STATUS_QUEUED, $job->status);
        $this->assertSame('upload://sample.pdf', $job->source_url);
        $this->assertSame($storedUpload->localPath, $job->local_path);
        $this->assertSame($storedUpload->contentHash, $job->content_hash);
        $this->assertSame($metadata, $job->metadata);
        $this->assertTrue($startedAt->equalTo($job->started_at));

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'convert_repository_job',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => PipelineJob::STATUS_QUEUED,
            'source_url' => 'upload://sample.pdf',
        ]);
    }

    private function dataset(): Dataset
    {
        return Dataset::query()->firstOrCreate(
            ['dataset_id' => 'repository-dataset'],
            [
                'name' => 'Repository Dataset',
                'description' => null,
                'status' => Dataset::STATUS_ACTIVE,
                'qdrant_collection' => 'hawki_repository_dataset',
                'neo4j_namespace' => 'hawki_repository_dataset',
                'created_at' => now(),
            ],
        );
    }
}
