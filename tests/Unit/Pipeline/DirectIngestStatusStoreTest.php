<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\DirectIngestStatusStore;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestStatusStoreTest extends TestCase
{
    public function test_it_resolves_paths_modes_and_live_ingestions(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-status-' . uniqid() . '.json';
        $cacheLogPath = sys_get_temp_dir() . '/hawki-ingest-cache-' . uniqid() . '.log';
        $fullLogPath = sys_get_temp_dir() . '/hawki-ingest-full-' . uniqid() . '.log';
        config()->set('config.ingest_status_path', $statusPath);
        config()->set('config.ingest_log_cache_path', $cacheLogPath);
        config()->set('config.ingest_log_path', $fullLogPath);

        File::put($statusPath, json_encode([
            'ingests' => [
                [
                    'pid' => 123,
                    'path' => '/tmp/running',
                    'status' => 'running',
                    'started_at' => '2026-01-01T00:00:00+00:00',
                    'updated_at' => '2026-01-01T00:01:00+00:00',
                    'collection' => 'hawki',
                ],
                [
                    'pid' => 456,
                    'path' => '/tmp/completed',
                    'status' => 'completed',
                ],
            ],
        ]));

        try {
            $store = app(DirectIngestStatusStore::class);
            $paths = $store->paths('invalid');

            $this->assertSame($statusPath, $paths->statusPath);
            $this->assertSame($cacheLogPath, $paths->cacheLogPath);
            $this->assertSame($fullLogPath, $paths->fullLogPath);
            $this->assertSame('neo4j', $store->modeForPayload(['graph_only' => true]));
            $this->assertSame('default', $store->normalizeMode('other'));

            $live = $store->live('default');
            $this->assertCount(1, $live);
            $this->assertSame(123, $live[0]['pid']);
            $this->assertSame('/tmp/running', $live[0]['path']);
            $this->assertSame('hawki', $live[0]['collection']);
        } finally {
            @unlink($statusPath);
            @unlink($cacheLogPath);
            @unlink($fullLogPath);
        }
    }

    public function test_it_loads_legacy_single_status_shape(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-status-legacy-' . uniqid() . '.json';
        File::put($statusPath, json_encode([
            'pid' => 123,
            'status' => 'running',
        ]));

        try {
            $entries = app(DirectIngestStatusStore::class)->load($statusPath);

            $this->assertCount(1, $entries);
            $this->assertSame('running', $entries[0]['status']);
        } finally {
            @unlink($statusPath);
        }
    }
}
