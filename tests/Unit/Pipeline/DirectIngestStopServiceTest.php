<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\DirectIngestStopService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestStopServiceTest extends TestCase
{
    public function test_it_returns_noop_when_no_ingests_are_running(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-stop-empty-' . uniqid() . '.json';
        config()->set('config.ingest_status_path', $statusPath);

        try {
            $result = app(DirectIngestStopService::class)->stop([]);

            $this->assertSame(200, $result->status);
            $this->assertSame(0, $result->payload['stopped_count']);
            $this->assertSame([], $result->payload['stopped_pids']);
            $this->assertSame([], $result->payload['live_ingestions']);
        } finally {
            @unlink($statusPath);
        }
    }

    public function test_it_marks_stale_running_pid_as_stopped(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-stop-' . uniqid() . '.json';
        $pid = 2147483647;
        config()->set('config.ingest_status_path', $statusPath);
        File::put($statusPath, json_encode([
            'ingests' => [[
                'id' => 'running-ingest',
                'pid' => $pid,
                'path' => '/tmp/running-ingest',
                'status' => 'running',
                'collection' => 'hawki',
            ]],
        ]));

        try {
            $result = app(DirectIngestStopService::class)->stop(['pid' => $pid]);
            $persisted = json_decode((string) file_get_contents($statusPath), true);

            $this->assertSame(200, $result->status);
            $this->assertSame(1, $result->payload['stopped_count']);
            $this->assertSame([$pid], $result->payload['stopped_pids']);
            $this->assertSame('stopped', $persisted['ingests'][0]['status']);
        } finally {
            @unlink($statusPath);
        }
    }
}
