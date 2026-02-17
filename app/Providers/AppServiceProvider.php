<?php

namespace App\Providers;

use App\Services\AI\Providers\OllamaProvider;
use App\Services\Mcp\McpClient;
use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\StorageService\StorageService;
use App\Services\StorageService\UrlGenerator;
use App\Services\WebSearchService\Interface\WebSearchInterface;
use App\Services\WebSearchService\WebSearchEngineFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('ollama.provider', function () {
            return new OllamaProvider();
        });

        $this->app->singleton(McpClient::class, function () {
            return new McpClient(
                config('mcp.base_url'),
                config('mcp.server'),
                (int) config('mcp.timeout', 30)
            );
        });

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
