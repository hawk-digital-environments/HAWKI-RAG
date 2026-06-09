<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Uploads\PipelineUploadIdentifierFactory;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineUploadIdentifierFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_upload_task_ids_with_timestamp_and_suffix(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 12, 34, 56));

        $taskId = app(PipelineUploadIdentifierFactory::class)->uploadTaskId();

        $this->assertMatchesRegularExpression(
            '/^task_controller_upload_20260608_123456_[a-z0-9]{6}$/',
            $taskId,
        );
    }

    public function test_it_builds_convert_job_id_from_task_id_hash_and_path(): void
    {
        $storedUpload = PipelineStoredUpload::fromStoredFile(
            'sample.pdf',
            'sample-abc123.pdf',
            '/shared/task/sample-abc123.pdf',
            'content-hash',
            'pdf',
        );

        $jobId = app(PipelineUploadIdentifierFactory::class)->convertJobId('task-1', $storedUpload);

        $this->assertSame(
            'convert_'.substr(hash('sha256', 'task-1|content-hash|/shared/task/sample-abc123.pdf'), 0, 24),
            $jobId,
        );
    }

    public function test_it_builds_source_url_from_original_upload_name(): void
    {
        $storedUpload = PipelineStoredUpload::fromStoredFile(
            'Original File.PDF',
            'original-file-abc123.pdf',
            '/shared/task/original-file-abc123.pdf',
            'content-hash',
            'pdf',
        );

        $this->assertSame(
            'upload://Original File.PDF',
            app(PipelineUploadIdentifierFactory::class)->sourceUrl($storedUpload),
        );
    }
}
