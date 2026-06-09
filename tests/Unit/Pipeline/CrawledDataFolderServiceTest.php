<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\CrawledDataFolderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CrawledDataFolderServiceTest extends TestCase
{
    public function test_it_lists_crawl_folders_and_skips_sitemap_folders(): void
    {
        $root = sys_get_temp_dir() . '/hawki-crawled-root-' . uniqid();
        File::ensureDirectoryExists($root . '/zeta');
        File::ensureDirectoryExists($root . '/alpha');
        File::ensureDirectoryExists($root . '/sitemap');
        config()->set('config.crawled_data_root', $root);

        try {
            $result = app(CrawledDataFolderService::class)->list();

            $this->assertSame(200, $result->status);
            $this->assertSame(realpath($root), $result->payload['root']);
            $this->assertSame(['alpha', 'zeta'], array_column($result->payload['folders'], 'name'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_it_deletes_only_folders_inside_crawled_root(): void
    {
        $root = sys_get_temp_dir() . '/hawki-crawled-root-delete-' . uniqid();
        $inside = $root . '/delete-me';
        $outside = sys_get_temp_dir() . '/hawki-crawled-outside-' . uniqid();
        File::ensureDirectoryExists($inside);
        File::ensureDirectoryExists($outside);
        config()->set('config.crawled_data_root', $root);

        try {
            $service = app(CrawledDataFolderService::class);
            $outsideResult = $service->delete($outside);
            $insideResult = $service->delete($inside);

            $this->assertSame(422, $outsideResult->status);
            $this->assertSame(200, $insideResult->status);
            $this->assertFalse(is_dir($inside));
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($outside);
        }
    }
}
