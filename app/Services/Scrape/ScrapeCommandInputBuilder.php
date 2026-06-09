<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ScrapeCommandInputBuilder
{
    public function __construct(
        private ConfigRepository $config,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function automationEnabled(): bool
    {
        return (bool) $this->config->get('config.pipeline_automation', false);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function request(string $url, array $options): array
    {
        $label = $this->resolveLabel($this->stringValue($options['label'] ?? null), $url);

        return [
            'url' => $url,
            'label' => $label,
            'maxPages' => (int) ($options['max-pages'] ?? 100),
            'outputDir' => $this->resolveOutputDir($this->stringValue($options['output-dir'] ?? null), $label),
            'skipImages' => (bool) ($options['skip-images'] ?? false),
            'imageExceptions' => $this->imageExceptions($this->stringValue($options['image-exceptions'] ?? null)),
            'dateSelector' => $this->stringValue($options['date'] ?? null),
            'maxConcurrency' => (int) ($options['max-concurrency'] ?? 4),
            'maxRpm' => (int) ($options['max-rpm'] ?? 60),
            'requestDelay' => isset($options['request-delay']) && $options['request-delay'] !== ''
                ? (int) $options['request-delay']
                : null,
            'discoveryMode' => (bool) ($options['discovery-mode'] ?? false),
        ];
    }

    public function imageExceptions(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $exceptions = collect(explode(',', $raw))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => filled($item))
            ->values()
            ->toArray();

        return $exceptions === [] ? null : implode(',', $exceptions);
    }

    public function resolveLabel(?string $label, string $url): string
    {
        if ($label !== null) {
            return $label;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $base = is_string($host) && $host !== '' ? $host : 'crawl';

        return Str::slug($base.'-'.$this->clock->now()->format('Ymd-His'), '-');
    }

    private function resolveOutputDir(?string $outputDir, ?string $label): string
    {
        if ($outputDir !== null) {
            return $this->resolvePath($outputDir);
        }

        $root = $this->crawledDataRoot();
        if ($label === null || $label === '') {
            return $root;
        }

        return $root.DIRECTORY_SEPARATOR.Str::slug($label, '-');
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->crawledDataRoot().DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function crawledDataRoot(): string
    {
        return rtrim((string) $this->config->get('config.crawled_data_root', '/app/shared'), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
