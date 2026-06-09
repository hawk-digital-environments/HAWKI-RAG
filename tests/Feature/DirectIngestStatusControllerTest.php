<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PipelineExitCode;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestStatusControllerTest extends TestCase
{
    public function test_show_endpoint_falls_back_to_default_mode_and_persists_terminal_status(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-status-controller-' . uniqid() . '.json';
        $logPath = sys_get_temp_dir() . '/hawki-ingest-status-controller-' . uniqid() . '.log';
        config()->set('config.ingest_status_path', $statusPath);
        config()->set('config.ingest_log_cache_path', $logPath);
        File::put($statusPath, json_encode([
            'ingests' => [[
                'id' => 'status-controller-ingest',
                'status' => 'running',
            ]],
        ]));
        File::put($logPath, implode(PHP_EOL, [
            'INGEST_EXIT_CODE=' . PipelineExitCode::PARTIAL_SUCCESS,
            'INGEST_FAILED',
            '',
        ]));

        try {
            $this->getJson('/ingest/status?mode=unsupported')
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('status.status', 'failed')
                ->assertJsonPath('status.exit_code', PipelineExitCode::PARTIAL_SUCCESS);

            $persisted = json_decode((string) file_get_contents($statusPath), true);
            $this->assertSame('failed', $persisted['ingests'][0]['status']);
        } finally {
            @unlink($statusPath);
            @unlink($logPath);
        }
    }

    public function test_clear_endpoint_removes_default_status_files(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-status-clear-controller-' . uniqid() . '.json';
        $logPath = sys_get_temp_dir() . '/hawki-ingest-status-clear-controller-' . uniqid() . '.log';
        config()->set('config.ingest_status_path', $statusPath);
        config()->set('config.ingest_log_cache_path', $logPath);
        File::put($statusPath, '{}');
        File::put($logPath, 'log');

        $this->postJson('/ingest/status/clear?mode=default')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFileDoesNotExist($statusPath);
        $this->assertFileDoesNotExist($logPath);
    }
}
