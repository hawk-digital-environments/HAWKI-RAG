<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Storage\StorageService;
use App\Services\Storage\StorageElementReader;
use App\Services\Storage\StorageJobReportReader;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UrlGenerator;
use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Implementations\BraveSearch;
use App\Services\WebSearch\Implementations\TavilySearch;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use App\Support\Clock\CarbonClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator as RoutingUrlGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator as LaravelUrlGenerator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Psr\Clock\ClockInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const SAFE_ROUTE_IDENTIFIER = '[A-Za-z0-9][A-Za-z0-9._:-]{0,190}';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, CarbonClock::class);

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
        $config = $this->app->make(ConfigRepository::class);
        /** @var LaravelUrlGenerator $url */
        $url = $this->app->make('url');
        $url->useOrigin((string) $config->get('app.url'));

        $this->configureViteAssetPaths($config);
        $this->registerRouteConstraints();
        $this->registerRateLimits();
    }

    private function configureViteAssetPaths(ConfigRepository $config): void
    {
        $basePath = '/' . trim((string) $config->get('app.asset_base_path', '/'), '/');
        $basePath = $basePath === '/' ? '' : $basePath;

        Vite::createAssetPathsUsing(
            fn (string $path, ?bool $secure = null): string => $basePath . '/' . ltrim($path, '/')
        );
    }

    private function registerRouteConstraints(): void
    {
        foreach (['collection', 'datasetId', 'documentId', 'id', 'jobId', 'taskId'] as $parameter) {
            Route::pattern($parameter, self::SAFE_ROUTE_IDENTIFIER);
        }
    }

    private function registerRateLimits(): void
    {
        RateLimiter::for('hawki-ui', fn (Request $request): Limit => Limit::perMinute(240)->by($this->rateLimitKey($request)));
        RateLimiter::for('hawki-health', fn (Request $request): Limit => Limit::perMinute(120)->by($this->rateLimitKey($request)));
        RateLimiter::for('hawki-api', fn (Request $request): Limit => Limit::perMinute(180)->by($this->rateLimitKey($request)));
        RateLimiter::for('hawki-rag-query', fn (Request $request): Limit => Limit::perMinute(30)->by($this->rateLimitKey($request)));
        RateLimiter::for('hawki-upload', fn (Request $request): Limit => Limit::perMinute(12)->by($this->rateLimitKey($request)));
        RateLimiter::for('hawki-destructive', fn (Request $request): Limit => Limit::perMinute(10)->by($this->rateLimitKey($request)));
    }

    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();
        if ($user !== null) {
            return 'user:'.$user->getAuthIdentifier();
        }

        return 'ip:'.($request->ip() ?? 'unknown');
    }
}
