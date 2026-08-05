<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Health\PipelineSharedStorageHealthCheck;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineSharedStorageHealthCheckTest extends TestCase
{
    public function test_it_reports_writable_shared_storage_paths(): void
    {
        $path = storage_path('framework/testing/shared-storage/'.(string) Str::uuid());
        File::ensureDirectoryExists($path);

        config()->set('temporal.storage.shared_root', $path);
        config()->set('temporal.storage.shared_storage_web_user', '');
        config()->set('scraper.storage_path', $path);
        config()->set('config.shared_root', $path);

        try {
            $result = app(PipelineSharedStorageHealthCheck::class)->check();

            $this->assertSame('ok', $result['status']);
            $this->assertSame('Shared storage', $result['name']);
            $this->assertStringContainsString($path, $result['detail']);
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_it_reports_worker_created_source_directory_that_php_cannot_write(): void
    {
        $root = '/shared-test';
        $sourcesRoot = $root.'/sources';
        $workerSource = $sourcesRoot.'/source-worker-owned';

        config()->set('temporal.storage.shared_root', $root);
        config()->set('temporal.storage.shared_storage_web_user', '');
        config()->set('scraper.storage_path', $root);
        config()->set('config.shared_root', $root);

        $files = $this->createMock(Filesystem::class);
        $files->method('isDirectory')
            ->willReturnCallback(fn (string $path): bool => in_array($path, [$root, $sourcesRoot], true));
        $files->method('isWritable')
            ->willReturnCallback(fn (string $path): bool => $path !== $workerSource);
        $files->method('directories')
            ->with($sourcesRoot)
            ->willReturn([$workerSource]);
        $files->method('put')->willReturn(2);
        $files->method('delete')->willReturn(true);

        $result = (new PipelineSharedStorageHealthCheck($this->app, config(), $files))->check();

        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString($workerSource, $result['detail']);
        $this->assertStringContainsString('named POSIX ACL', $result['detail']);
        $this->assertStringContainsString('getfacl', $result['fix']);
        $this->assertStringContainsString('named/default POSIX ACL', $result['fix']);
    }
}
