<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Commands;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineDemoCommandSupport
{
    private const DEFAULT_URLS = [
        'https://www.hawk.de/de',
        'https://www.hawk.de/de/studium',
        'https://www.hawk.de/de/hochschule',
        'https://www.hawk.de/de/forschung',
        'https://www.hawk.de/de/weiterbildung',
    ];

    public function __construct(
        private Application $app,
        private ConfigRepository $config,
        private UrlGenerator $urls,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function productionLocked(bool $force): bool
    {
        return $this->app->environment('production') && $force !== true;
    }

    public function taskId(): string
    {
        return 'demo_'.$this->clock->now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
    }

    /**
     * @param list<string> $explicit
     * @return list<string>
     */
    public function demoUrls(array $explicit, int $limit): array
    {
        $urls = $explicit ?: $this->configuredDemoUrls();
        if ($urls === []) {
            $urls = self::DEFAULT_URLS;
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            fn (mixed $url): ?string => is_scalar($url) && trim((string) $url) !== '' ? trim((string) $url) : null,
            $urls,
        )))), 0, $limit);
    }

    /**
     * @return list<string>
     */
    public function dashboardUrls(): array
    {
        $dashboard = $this->urls->to('/pipeline-controller');
        $urls = [$dashboard];
        $mounted = $this->mountedDashboardUrl();

        if ($mounted !== null && $mounted !== $dashboard) {
            $urls[] = $mounted;
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function configuredDemoUrls(): array
    {
        $configured = (string) $this->config->get('config.pipeline_demo_urls', '');
        if (trim($configured) === '') {
            return [];
        }

        return preg_split('/[\r\n,]+/', $configured) ?: [];
    }

    private function mountedDashboardUrl(): ?string
    {
        $appUrl = rtrim((string) $this->config->get('app.url'), '/');
        if ($appUrl === '') {
            return null;
        }

        $parts = parse_url($appUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($path === '') {
            $path = trim((string) ($this->config->get('config.docker_project_path') ?: $this->config->get('config.virtual_path', '')), '/');
        }

        if ($path === '') {
            return null;
        }

        return $origin.'/'.trim($path.'/pipeline-controller', '/');
    }
}
