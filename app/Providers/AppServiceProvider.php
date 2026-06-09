<?php

namespace App\Providers;

use App\Services\Storage\StorageService;
use App\Services\Storage\UrlGenerator;
use App\Services\WebSearchService\Implementations\BraveSearch;
use App\Services\WebSearchService\Implementations\TavilySearch;
use App\Services\WebSearchService\Interface\WebSearchInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
            fn() => match (config('web_search.default')) {
                'brave' => new BraveSearch(),
                'tavily' => new TavilySearch(),
                default => throw new \InvalidArgumentException(
                    'Invalid Default WebSearch Engine'
                )
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
