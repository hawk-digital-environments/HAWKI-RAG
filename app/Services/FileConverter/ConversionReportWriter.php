<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

readonly class ConversionReportWriter
{
    public function __construct(
        private Filesystem $files,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $failed
     */
    public function writeFailedJson(array $failed, int $processed, int $total, int $skipped): void
    {
        $payload = [
            'generated_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'total' => $total,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => count($failed),
            'failures' => $failed,
        ];

        $dest = storage_path('logs/failed_conversion.json');
        $tmp = $dest . '.tmp';
        $this->files->ensureDirectoryExists(dirname($dest));
        $this->files->put($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $dest);
    }
}
