<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class McpMonitorController extends Controller
{
    public function latest(): JsonResponse
    {
        $path = (string) config('mcp.log_path', storage_path('app/processRAG_log.txt'));
        if (! is_file($path)) {
            return response()->json(['ok' => false, 'message' => 'Log file not found', 'latest' => null, 'lines' => []]);
        }

        $lines = $this->tailLines($path, 20);
        $latest = $lines ? $lines[count($lines) - 1] : null;

        return response()->json([
            'ok' => true,
            'latest' => $latest,
            'lines' => $lines,
        ]);
    }

    public function clear(): JsonResponse
    {
        $path = (string) config('mcp.log_path', storage_path('app/processRAG_log.txt'));
        if (is_file($path)) {
            @unlink($path);
        }
        return response()->json(['ok' => true]);
    }

    private function tailLines(string $path, int $count): array
    {
        $count = max(1, $count);
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $start = max(0, $lastLine - $count);
        $lines = [];
        for ($i = $start; $i <= $lastLine; $i++) {
            $file->seek($i);
            $line = trim((string) $file->current());
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }
}
