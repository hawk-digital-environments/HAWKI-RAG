<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use SplFileObject;

#[Singleton]
readonly class DirectIngestLogReader
{
    public function __construct(private Filesystem $files)
    {
    }

    /**
     * @return list<string>
     */
    public function tailLines(string $path, int $count): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        $count = max(1, $count);
        $file = new SplFileObject($path, 'r');
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

    /**
     * @param list<string> $lines
     */
    public function exitCode(array $lines): ?int
    {
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^INGEST_EXIT_CODE=(\\d+)$/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    public function progress(array $lines): array
    {
        $progress = [];
        foreach (array_reverse($lines) as $line) {
            if (! isset($progress['folders']) && preg_match('/Folder\\s+(\\d+)[\\/](\\d+)/', $line, $matches) === 1) {
                $progress['folders'] = [
                    'current' => (int) $matches[1],
                    'total' => (int) $matches[2],
                ];
            }
            if (! isset($progress['docs']) && preg_match('/Sent\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $matches) === 1) {
                $progress['docs'] = [
                    'sent' => (int) $matches[1],
                    'total' => (int) $matches[2],
                ];
            }
            if (! isset($progress['docs']) && preg_match('/Planned\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $matches) === 1) {
                $progress['docs'] = [
                    'sent' => (int) $matches[1],
                    'total' => (int) $matches[2],
                    'mode' => 'dry',
                ];
            }
            if (count($progress) >= 2) {
                break;
            }
        }

        return $progress;
    }
}
