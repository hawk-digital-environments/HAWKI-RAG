<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Health\PipelineSharedStorageHealthCheck;
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
}
