<?php

namespace Tests\Feature;

use App\Support\PipelineExitCode;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PipelineExitCodeTest extends TestCase
{
    public function test_scrape_command_returns_validation_failure_without_url_in_non_interactive_mode(): void
    {
        $exitCode = Artisan::call('scraper:scrape', [
            '--no-interaction' => true,
        ]);

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
    }

    public function test_convert_command_returns_validation_failure_for_missing_directory(): void
    {
        $exitCode = Artisan::call('convert:crawled-pdfs', [
            'outputDir' => '/tmp/hawki-rag-test-missing-convert-dir',
            '--no-interaction' => true,
        ]);

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
    }

    public function test_publish_converted_folder_returns_validation_failure_for_missing_directory(): void
    {
        $exitCode = Artisan::call('rag:publish-converted-folder', [
            'folder' => '/tmp/hawki-rag-test-missing-publish-dir',
            '--no-interaction' => true,
        ]);

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
    }

    public function test_python_ingest_script_returns_validation_failure_for_missing_root(): void
    {
        $process = new Process([
            'python3',
            base_path('python_rag/ingest/ingest_crawled.py'),
            '--root',
            '/tmp/hawki-rag-test-missing-ingest-root',
        ], base_path());

        $process->run();

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_ingest_status_endpoint_persists_detached_process_exit_code(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-rag-ingest-status-test.json';
        $logPath = sys_get_temp_dir() . '/hawki-rag-ingest-log-test.log';

        @unlink($statusPath);
        @unlink($logPath);

        config([
            'config.ingest_status_path' => $statusPath,
            'config.ingest_log_cache_path' => $logPath,
        ]);

        file_put_contents($statusPath, json_encode([
            'ingests' => [[
                'id' => 'test-ingest',
                'status' => 'running',
                'started_at' => '2026-05-12T00:00:00+00:00',
            ]],
        ], JSON_PRETTY_PRINT));
        file_put_contents($logPath, implode(PHP_EOL, [
            'Scanning: /tmp/example',
            'INGEST_EXIT_CODE=' . PipelineExitCode::PARTIAL_SUCCESS,
            'INGEST_FAILED',
            '',
        ]));

        $response = $this->getJson('/ingest/status?mode=default');

        $response
            ->assertOk()
            ->assertJsonPath('status.status', 'failed')
            ->assertJsonPath('status.exit_code', PipelineExitCode::PARTIAL_SUCCESS);

        $persisted = json_decode((string) file_get_contents($statusPath), true);
        $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $persisted['ingests'][0]['exit_code'] ?? null);

        @unlink($statusPath);
        @unlink($logPath);
    }
}
