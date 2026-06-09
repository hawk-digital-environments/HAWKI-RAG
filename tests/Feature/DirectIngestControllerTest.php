<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestControllerTest extends TestCase
{
    public function test_folders_endpoint_lists_crawled_folders(): void
    {
        $root = sys_get_temp_dir() . '/hawki-ingest-controller-folders-' . uniqid();
        File::ensureDirectoryExists($root . '/beta');
        File::ensureDirectoryExists($root . '/alpha');
        File::ensureDirectoryExists($root . '/sitemaps');
        config()->set('config.crawled_data_root', $root);

        try {
            $this->getJson('/ingest/folders')
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('folders.0.name', 'alpha')
                ->assertJsonPath('folders.1.name', 'beta');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_live_endpoint_reads_running_ingests_from_status_store(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-controller-live-' . uniqid() . '.json';
        config()->set('config.ingest_status_path', $statusPath);
        File::put($statusPath, json_encode([
            'ingests' => [[
                'pid' => 123,
                'path' => '/tmp/live',
                'status' => 'running',
                'collection' => 'hawki',
            ]],
        ]));

        try {
            $this->getJson('/ingest/live?mode=default')
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('live_ingestions.0.pid', 123)
                ->assertJsonPath('live_ingestions.0.collection', 'hawki');
        } finally {
            @unlink($statusPath);
        }
    }

    public function test_stop_endpoint_marks_stale_process_as_stopped(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-controller-stop-' . uniqid() . '.json';
        $pid = 2147483647;
        config()->set('config.ingest_status_path', $statusPath);
        File::put($statusPath, json_encode([
            'ingests' => [[
                'id' => 'running-ingest',
                'pid' => $pid,
                'path' => '/tmp/live',
                'status' => 'running',
            ]],
        ]));

        try {
            $this->postJson('/ingest/stop', ['pid' => $pid])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('stopped_count', 1)
                ->assertJsonPath('stopped_pids.0', $pid);

            $persisted = json_decode((string) file_get_contents($statusPath), true);
            $this->assertSame('stopped', $persisted['ingests'][0]['status']);
        } finally {
            @unlink($statusPath);
        }
    }

    public function test_delete_endpoint_deletes_folder_inside_crawled_root(): void
    {
        $root = sys_get_temp_dir() . '/hawki-ingest-controller-delete-' . uniqid();
        $path = $root . '/delete-me';
        File::ensureDirectoryExists($path);
        config()->set('config.crawled_data_root', $root);

        try {
            $this->postJson('/ingest/delete', ['path' => $path])
                ->assertOk()
                ->assertJsonPath('ok', true);

            $this->assertFalse(is_dir($path));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
