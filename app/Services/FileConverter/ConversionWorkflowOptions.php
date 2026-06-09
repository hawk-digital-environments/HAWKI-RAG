<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class ConversionWorkflowOptions
{
    public function __construct(
        private ConfigRepository $config,
    ) {
    }

    public function jobId(string $outputDir): string
    {
        return 'convert:'.substr(hash('sha256', realpath($outputDir) ?: $outputDir), 0, 16);
    }

    public function maxRetries(): int
    {
        return (int) $this->config->get('file_converter.retries', 3);
    }

    public function retryDelayMs(): int
    {
        return (int) $this->config->get('file_converter.retry_delay_ms', 1500);
    }

    /**
     * @return array<int, string>
     */
    public function extensions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $this->configuredExtensions();
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn ($ext) => $ext !== '');
        $parts = array_map(static fn ($ext) => ltrim(strtolower($ext), '.'), $parts);

        return $parts ?: $this->configuredExtensions();
    }

    /**
     * @return array<int, string>
     */
    private function configuredExtensions(): array
    {
        $extensions = $this->config->get('file_converter.supported_extensions', ['pdf', 'doc', 'docx']);
        if (! is_array($extensions)) {
            return ['pdf', 'doc', 'docx'];
        }

        $extensions = array_values(array_filter(
            array_map(static fn ($extension) => is_scalar($extension) ? ltrim(strtolower(trim((string) $extension)), '.') : '', $extensions),
            static fn ($extension) => $extension !== ''
        ));

        return $extensions ?: ['pdf', 'doc', 'docx'];
    }
}
