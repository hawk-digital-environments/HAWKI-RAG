<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Storage\StorageService;
use App\Services\Storage\StorageElementReader;
use App\Services\Storage\StorageJobReportReader;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UrlGenerator;
use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Implementations\BraveSearch;
use App\Services\WebSearch\Implementations\TavilySearch;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use App\Support\Clock\CarbonClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator as RoutingUrlGenerator;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Routing\UrlGenerator as LaravelUrlGenerator;
use Psr\Clock\ClockInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, CarbonClock::class);
        $this->app->singleton(PipelineEventConfig::class);

        $this->app->singleton(StorageService::class, function (Application $app) {
            $config = $app->make(ConfigRepository::class);
            $diskName = (string) $config->get('filesystems.file_storage');
            $disk = $app->make(FilesystemManager::class)->disk($diskName);
            $urlGenerator = new UrlGenerator(
                (array) $config->get('filesystems.disks.' . $diskName),
                $disk,
                $app->make(RoutingUrlGenerator::class),
            );
            $paths = new StoragePathBuilder();

            return new StorageService(
                new StorageJobReportReader($disk, $paths),
                new StorageElementReader($disk, $paths, $urlGenerator),
            );
        });

        $this->app->singleton(
            WebSearchInterface::class,
            function (Application $app): WebSearchInterface {
                $config = $app->make(ConfigRepository::class);
                $provider = (string) $config->get('web_search.default');

                return match ($provider) {
                'brave' => $app->make(BraveSearch::class),
                'tavily' => $app->make(TavilySearch::class),
                default => throw WebSearchFailedException::invalidDefaultProvider($provider),
                };
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /** @var LaravelUrlGenerator $url */
        $url = $this->app->make('url');
        $url->useOrigin((string) $this->app->make(ConfigRepository::class)->get('app.url'));
    }
}
