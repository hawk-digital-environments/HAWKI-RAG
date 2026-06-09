<?php

declare(strict_types=1);

namespace App\Services\Pipeline\DirectIngest;

use App\Services\Pipeline\Values\DirectIngestStatusPaths;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DirectIngestProcessLauncher
{
    public function launch(array $cmd, array $data, DirectIngestStatusPaths $paths): int
    {
        $process = @proc_open(
            ['/usr/bin/setsid', '/bin/sh', '-lc', $this->commandLine($cmd, $data, $paths)],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            base_path(),
            null,
            ['bypass_shell' => true],
        );

        $status = is_resource($process) ? proc_get_status($process) : [];
        foreach ($pipes ?? [] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
        unset($process);

        return $pid;
    }

    public function commandLine(array $cmd, array $data, DirectIngestStatusPaths $paths): string
    {
        $command = implode(' ', array_map('escapeshellarg', $cmd));
        $graphModel = isset($data['graph_model']) ? trim((string) $data['graph_model']) : '';
        $envPrefix = $graphModel !== '' ? ('export GRAPH_OLLAMA_RAG_MODEL='.escapeshellarg($graphModel).'; ') : '';
        $cacheEsc = escapeshellarg($paths->cacheLogPath);
        $fullEsc = escapeshellarg($paths->fullLogPath);

        return $envPrefix
            .'{ '.$command.' 2>&1; exit_code=$?; '
            .'echo "INGEST_EXIT_CODE=${exit_code}"; '
            .'if [ "$exit_code" -eq 0 ]; then echo "INGEST_DONE"; else echo "INGEST_FAILED"; fi; '
            .'} | tee -a '.$fullEsc.' >> '.$cacheEsc;
    }
}
