<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class DirectIngestProcessInspector
{
    public function __construct(
        private Filesystem $files,
    ) {
    }

    public function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        @exec('kill -0 '.$pid, $out, $code);

        return $code === 0;
    }

    public function terminate(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill') && @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15)) {
            return true;
        }

        @exec('kill -TERM '.$pid, $out, $code);

        return $code === 0;
    }

    public function stopByCommandMatch(string $needle): int
    {
        $count = 0;
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
            $cmdline = $this->cmdline($cmdlinePath);
            if ($cmdline === null || stripos($cmdline, $needle) === false) {
                continue;
            }

            if (preg_match('~/proc/(\\d+)/cmdline$~', $cmdlinePath, $matches) !== 1) {
                continue;
            }

            if ($this->terminate((int) $matches[1])) {
                $count += 1;
            }
        }

        return $count;
    }

    private function cmdline(string $cmdlinePath): ?string
    {
        try {
            $cmdline = $this->files->isFile($cmdlinePath)
                ? $this->files->get($cmdlinePath)
                : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($cmdline) && $cmdline !== ''
            ? str_replace("\0", ' ', $cmdline)
            : null;
    }
}
