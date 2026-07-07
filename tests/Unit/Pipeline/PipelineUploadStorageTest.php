<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Uploads\PipelineUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PipelineUploadStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/pipeline-upload-storage');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists(dirname($this->root));
        File::ensureDirectoryExists($this->root);
        config()->set('temporal.storage.shared_root', $this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_stores_upload_and_returns_stored_file_details(): void
    {
        $storage = app(PipelineUploadStorage::class);
        $file = UploadedFile::fake()->createWithContent('My Upload.PDF', 'stored upload body');

        $stored = $storage->store('task_test_upload', $file, 'pdf');

        $this->assertSame('My Upload.PDF', $stored->originalName);
        $this->assertSame('pdf', $stored->extension);
        $this->assertStringStartsWith('my-upload-', $stored->targetName);
        $this->assertStringEndsWith('.pdf', $stored->targetName);
        $this->assertSame(
            $this->root.DIRECTORY_SEPARATOR.'task_test_upload'.DIRECTORY_SEPARATOR.$stored->targetName,
            $stored->localPath,
        );
        $this->assertFileExists($stored->localPath);
        $this->assertSame(hash_file('sha256', $stored->localPath), $stored->contentHash);
    }

    public function test_it_throws_domain_exception_when_task_path_is_blocked(): void
    {
        $taskId = 'task_blocked_upload';
        File::ensureDirectoryExists($this->root);
        File::put($this->root.DIRECTORY_SEPARATOR.$taskId, 'not a directory');

        $storage = app(PipelineUploadStorage::class);
        $file = UploadedFile::fake()->createWithContent('blocked.pdf', 'blocked body');

        try {
            $storage->store($taskId, $file, 'pdf');
            $this->fail('Expected upload storage to throw when the task path is blocked.');
        } catch (PipelineUploadStorageException $exception) {
            $this->assertSame(
                'The upload storage path is not writable. No heap, task, or job was created.',
                $exception->responseMessage(),
            );
            $this->assertSame('Pipeline controller could not prepare upload storage.', $exception->logMessage());
            $this->assertSame(
                $this->root.DIRECTORY_SEPARATOR.$taskId,
                $exception->logContext()['task_root'] ?? null,
            );
        }
    }
}
