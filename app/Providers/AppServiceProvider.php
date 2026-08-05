<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\DatasetQueryPolicy;
use App\Policies\UserAccessPolicy;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Implementations\BraveSearch;
use App\Services\WebSearch\Implementations\TavilySearch;
use App\Support\Clock\CarbonClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator as LaravelUrlGenerator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

class AppServiceProvider extends ServiceProvider
{
    private const SAFE_ROUTE_IDENTIFIER = '[A-Za-z0-9][A-Za-z0-9._:-]{0,190}';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, CarbonClock::class);

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
        $this->registerAuthorizationGates();
        $this->registerRouteConstraints();
        $this->registerRateLimits();
    }

    private function registerAuthorizationGates(): void
    {
        Gate::define('access-active-user', [UserAccessPolicy::class, 'accessActiveUser']);
        Gate::define('access-query-principal', [UserAccessPolicy::class, 'accessQueryPrincipal']);
        Gate::define('query-dataset', [DatasetQueryPolicy::class, 'query']);
    }

    private function configureViteAssetPaths(ConfigRepository $config): void
    {
        $basePath = '/'.trim((string) $config->get('app.asset_base_path', '/'), '/');
        $basePath = $basePath === '/' ? '' : $basePath;

        Vite::createAssetPathsUsing(
            fn (string $path, ?bool $secure = null): string => $basePath.'/'.ltrim($path, '/')
        );
    }

    private function registerRouteConstraints(): void
    {
        foreach (['datasetId', 'documentId', 'id', 'jobId', 'taskId'] as $parameter) {
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
        RateLimiter::for('hawki-pipeline-worker-events', fn (Request $request): Limit => Limit::perMinute(600)->by('worker-ip:'.($request->ip() ?? 'unknown')));
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
