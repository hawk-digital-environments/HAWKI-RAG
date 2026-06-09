<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\DirectIngestStatusService;
use App\Support\PipelineExitCode;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DirectIngestStatusServiceTest extends TestCase
{
    public function test_it_marks_terminal_failed_status_and_extracts_progress(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-ingest-status-service-' . uniqid() . '.json';
        $logPath = sys_get_temp_dir() . '/hawki-ingest-status-service-' . uniqid() . '.log';
        config()->set('config.ingest_status_path', $statusPath);
        config()->set('config.ingest_log_cache_path', $logPath);
        File::put($statusPath, json_encode([
            'ingests' => [[
                'id' => 'status-service-ingest',
                'status' => 'running',
                'started_at' => '2026-05-12T00:00:00+00:00',
            ]],
        ]));
        File::put($logPath, implode(PHP_EOL, [
            'Folder 1/2',
            'Sent 3/5 docs',
            'INGEST_EXIT_CODE=' . PipelineExitCode::PARTIAL_SUCCESS,
            'INGEST_FAILED',
            '',
        ]));

        try {
            $result = app(DirectIngestStatusService::class)->show('default');
            $persisted = json_decode((string) file_get_contents($statusPath), true);

            $this->assertSame(200, $result->status);
            $this->assertSame('failed', $result->payload['status']['status']);
            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $result->payload['status']['exit_code']);
            $this->assertSame(1, $result->payload['status']['progress']['folders']['current']);
            $this->assertSame(3, $result->payload['status']['progress']['docs']['sent']);
            $this->assertSame('failed', $persisted['ingests'][0]['status']);
            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $persisted['ingests'][0]['exit_code']);
        } finally {
            @unlink($statusPath);
            @unlink($logPath);
        }
    }

    public function test_it_clears_default_and_neo4j_status_targets(): void
    {
        $defaultStatusPath = sys_get_temp_dir() . '/hawki-ingest-clear-default-' . uniqid() . '.json';
        $defaultLogPath = sys_get_temp_dir() . '/hawki-ingest-clear-default-' . uniqid() . '.log';
        $neo4jStatusPath = sys_get_temp_dir() . '/hawki-ingest-clear-neo4j-' . uniqid() . '.json';
        $neo4jLogPath = sys_get_temp_dir() . '/hawki-ingest-clear-neo4j-' . uniqid() . '.log';
        config()->set('config.ingest_status_path', $defaultStatusPath);
        config()->set('config.ingest_log_cache_path', $defaultLogPath);
        config()->set('config.ingest_status_path_neo4j', $neo4jStatusPath);
        config()->set('config.ingest_log_cache_path_neo4j', $neo4jLogPath);
        File::put($defaultStatusPath, '{}');
        File::put($defaultLogPath, 'log');
        File::put($neo4jStatusPath, '{}');
        File::put($neo4jLogPath, 'log');

        $result = app(DirectIngestStatusService::class)->clear('both');

        $this->assertSame(200, $result->status);
        $this->assertSame(['ok' => true], $result->payload);
        $this->assertFileDoesNotExist($defaultStatusPath);
        $this->assertFileDoesNotExist($defaultLogPath);
        $this->assertFileDoesNotExist($neo4jStatusPath);
        $this->assertFileDoesNotExist($neo4jLogPath);
    }
}
