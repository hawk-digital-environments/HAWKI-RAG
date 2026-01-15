<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RagFolderIngestTool extends Tool
{
    public function description(): string
    {
        return 'Ingest a crawled folder by auto-deriving payloads from .json/.md/.txt and converted PDFs.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('root')->description('Root folder containing crawled data')->required()
            ->string('base_url')->description('RAWKI bridge base URL (default: RAWKI_BRIDGE_URL)')
            ->string('provider')->description('Embedding provider, e.g. ollama')
            ->boolean('graph')->description('Enable Neo4j triplet extraction')
            ->string('graph_engine')->description('Graph engine: fallback or lightrag')
            ->string('collection')->description('Qdrant collection override')
            ->string('distance')->description('Qdrant distance: Cosine|Dot|Euclid')
            ->integer('chunk_chars')->description('Chunk size in characters')
            ->integer('chunk_overlap')->description('Chunk overlap in characters')
            ->integer('batch')->description('Docs per request')
            ->integer('timeout')->description('HTTP timeout seconds for bridge requests')
            ->boolean('dry')->description('Dry run: estimate counts only')
            ->boolean('dry_include_graph')->description('Estimate Neo4j triplets during dry run')
            ->boolean('estimate_only')->description('Estimate points locally without contacting bridge');
    }

    public function handle(array $arguments): ToolResult
    {
        $root = trim((string) ($arguments['root'] ?? ''));
        if ($root === '') {
            return ToolResult::error('root is required');
        }

        $script = base_path('python_rag/ingest/ingest_crawled.py');
        if (!is_file($script)) {
            return ToolResult::error('ingest_crawled.py not found');
        }

        $baseUrl = (string) ($arguments['base_url'] ?? env('RAWKI_BRIDGE_URL', 'http://rawki_bridge:8000'));
        $resumeDir = storage_path('app/private/ingest-state-mcp/' . uniqid('run_', true));
        File::ensureDirectoryExists($resumeDir);
        $collection = (string) ($arguments['collection'] ?? basename($root));

        $cmd = [
            'python3',
            $script,
            '--root', $root,
            '--base-url', $baseUrl,
            '--resume-state-dir', $resumeDir,
        ];

        $this->pushOptionalArg($cmd, '--provider', $arguments['provider'] ?? null);
        $this->pushOptionalArg($cmd, '--graph-engine', $arguments['graph_engine'] ?? null);
        $this->pushOptionalArg($cmd, '--collection', $collection);
        $this->pushOptionalArg($cmd, '--distance', $arguments['distance'] ?? null);
        $this->pushOptionalArg($cmd, '--chunk-chars', $arguments['chunk_chars'] ?? null);
        $this->pushOptionalArg($cmd, '--chunk-overlap', $arguments['chunk_overlap'] ?? null);
        $this->pushOptionalArg($cmd, '--batch', $arguments['batch'] ?? null);
        $this->pushOptionalArg($cmd, '--timeout', $arguments['timeout'] ?? null);

        if (!empty($arguments['graph'])) {
            $cmd[] = '--graph';
        }
        if (!empty($arguments['dry'])) {
            $cmd[] = '--dry';
        }
        if (!empty($arguments['dry_include_graph'])) {
            $cmd[] = '--dry-include-graph';
        }
        if (!empty($arguments['estimate_only'])) {
            $cmd[] = '--estimate-only';
        }

        $summaryPath = storage_path('logs/ingest_summary.json');
        $cmd[] = '--summary-file';
        $cmd[] = $summaryPath;

        $process = new Process($cmd, base_path());
        $process->setTimeout(3600);

        $statusPath = (string) config('rawki.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('rawki.ingest_log_path', storage_path('logs/ingest_progress.log'));
        File::ensureDirectoryExists(dirname($statusPath));

        $this->logMcpEvent('start', [
            'root' => $root,
            'base_url' => $baseUrl,
        ]);

        $status = [
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => $summaryPath,
            'command' => $cmd,
        ];
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process->run(function ($type, $buffer) use (&$status, $statusPath, $logPath) {
            $lines = preg_split("/\\r?\\n/", $buffer, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                File::append($logPath, $line . PHP_EOL);
                $status['last_line'] = $line;
                $progress = $this->extractProgress($line);
                if ($progress) {
                    $status['progress'] = $progress;
                }
                $status['updated_at'] = now()->toIso8601String();
                File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        });

        if (!$process->isSuccessful()) {
            $status['status'] = 'failed';
            $status['exit_code'] = $process->getExitCode();
            $status['updated_at'] = now()->toIso8601String();
            File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->logMcpEvent('failed', [
                'exit_code' => $process->getExitCode(),
            ]);
            return ToolResult::json([
                'ok' => false,
                'error' => $process->getErrorOutput() ?: 'Ingest process failed',
                'status_path' => $statusPath,
                'log_path' => $logPath,
            ]);
        }

        $summary = null;
        if (is_file($summaryPath)) {
            $summaryRaw = @file_get_contents($summaryPath);
            $summary = $summaryRaw ? json_decode($summaryRaw, true) : null;
        }

        $status['status'] = 'completed';
        $status['updated_at'] = now()->toIso8601String();
        $status['summary'] = $summary;
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->logMcpEvent('completed', [
            'summary_path' => $summaryPath,
        ]);

        return ToolResult::json([
            'ok' => true,
            'command' => $cmd,
            'summary' => $summary,
            'output' => $process->getOutput(),
            'status_path' => $statusPath,
            'log_path' => $logPath,
        ]);
    }

    /**
     * @param array<int,string> $cmd
     * @param mixed $value
     */
    private function pushOptionalArg(array &$cmd, string $flag, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $cmd[] = $flag;
        $cmd[] = (string) $value;
    }

    private function extractProgress(string $line): ?array
    {
        if (preg_match('/Sent\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
            return ['sent' => (int) $m[1], 'total' => (int) $m[2]];
        }
        if (preg_match('/Planned\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
            return ['sent' => (int) $m[1], 'total' => (int) $m[2], 'mode' => 'dry'];
        }
        if (preg_match('/Found\\s+(\\d+)\\s+PDF/i', $line, $m)) {
            return ['found_pdfs' => (int) $m[1]];
        }
        return null;
    }

    private function logMcpEvent(string $status, array $context = []): void
    {
        $path = (string) config('mcp.log_path', storage_path('app/processRAG_log.txt'));
        File::ensureDirectoryExists(dirname($path));
        $timestamp = date('Y-m-d H:i:s');
        $payload = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        File::append($path, sprintf("[%s] MCP tool=rag-folder-ingest-tool status=%s%s\n", $timestamp, $status, $payload));
    }
}
