<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineFailedEventFactory;
use Tests\TestCase;

class PipelineFailedEventFactoryTest extends TestCase
{
    public function test_it_builds_a_normalized_failed_event_with_original_context(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.max_retries', 7);

        $original = PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => 'task-failed-factory',
            'job_id' => 'convert-failed-factory',
            'dataset_id' => 'dataset-failed-factory',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/file.pdf',
            'local_path' => '/app/shared/file.pdf',
            'retry_count' => 2,
        ]);

        $failed = app(PipelineFailedEventFactory::class)->make($original, new \RuntimeException('Conversion failed.'));

        $this->assertSame(PipelineEvent::JOB_FAILED, $failed['event_type']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('task-failed-factory', $failed['task_id']);
        $this->assertSame('convert-failed-factory', $failed['job_id']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $failed['job_type']);
        $this->assertSame('RuntimeException', $failed['metadata']['error_type']);
        $this->assertSame('Conversion failed.', $failed['metadata']['error_message']);
        $this->assertSame(PipelineEvent::FILE_DISCOVERED, $failed['metadata']['original_event_type']);
        $this->assertSame($original, $failed['metadata']['original_event_payload']);
        $this->assertSame(2, $failed['metadata']['retry_count']);
        $this->assertSame(7, $failed['metadata']['max_retries']);
    }
}
