<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\DirectIngest\DirectIngestLaunchService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestLaunchServiceTest extends TestCase
{
    public function test_it_rejects_missing_crawled_data_root(): void
    {
        config()->set('config.crawled_data_root', sys_get_temp_dir().'/missing-ingest-root-'.uniqid());

        $result = app(DirectIngestLaunchService::class)->launch([
            'path' => sys_get_temp_dir(),
        ]);

        $this->assertSame(404, $result->status);
        $this->assertSame([
            'ok' => false,
            'message' => 'Crawled-data root not found.',
        ], $result->payload);
    }

    public function test_it_rejects_paths_outside_crawled_data_root(): void
    {
        $root = sys_get_temp_dir().'/ingest-root-'.uniqid();
        $outside = sys_get_temp_dir().'/ingest-outside-'.uniqid();
        File::ensureDirectoryExists($root);
        File::ensureDirectoryExists($outside);
        config()->set('config.crawled_data_root', $root);

        try {
            $result = app(DirectIngestLaunchService::class)->launch([
                'path' => $outside,
            ]);

            $this->assertSame(422, $result->status);
            $this->assertSame([
                'ok' => false,
                'message' => 'Path must be within the crawled-data root.',
            ], $result->payload);
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($outside);
        }
    }
}
