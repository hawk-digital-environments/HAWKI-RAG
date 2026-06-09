<?php

namespace App\Providers;

use App\Services\Storage\StorageService;
use App\Services\Storage\UrlGenerator;
use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\WebSearch\Exceptions\WebSearchFailedException;
use App\Services\WebSearch\Implementations\BraveSearch;
use App\Services\WebSearch\Implementations\TavilySearch;
use App\Services\WebSearch\Contracts\WebSearchInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PipelineEventConfig::class);

        $this->app->singleton(StorageService::class, function ($app) {
            $diskName = config('filesystems.file_storage');
            $disk = Storage::disk($diskName);
            $urlGenerator = new UrlGenerator(config('filesystems.disks.' . $diskName), $disk);
            return new StorageService(
                $disk,
                $urlGenerator
            );
        });

        $this->app->singleton(
            WebSearchInterface::class,
            fn (Application $app) => match ((string) $app['config']->get('web_search.default')) {
                'brave' => $app->make(BraveSearch::class),
                'tavily' => $app->make(TavilySearch::class),
                default => throw WebSearchFailedException::invalidDefaultProvider((string) $app['config']->get('web_search.default')),
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['url']->useOrigin($this->app['config']->get('app.url'));
    }
}
