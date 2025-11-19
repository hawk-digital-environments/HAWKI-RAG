<?php

namespace App\Providers;

use App\Services\AI\Providers\OllamaProvider;

use App\Services\Storage\FileStorageService;
use App\Services\Storage\StorageServiceFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\ExternalServerAuth;
use Illuminate\Support\Facades\Route;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
