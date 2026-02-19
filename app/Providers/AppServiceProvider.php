<?php

namespace App\Providers;

use App\Services\StorageService\StorageService;
use App\Services\StorageService\UrlGenerator;
use App\Services\WebSearchService\Interface\WebSearchInterface;
use App\Services\WebSearchService\WebSearchEngineFactory;
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
            WebSearchInterface::class, function ($app) {
                return WebSearchEngineFactory::make();
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
