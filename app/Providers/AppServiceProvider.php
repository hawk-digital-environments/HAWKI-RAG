<?php

namespace App\Providers;

use App\Services\AI\Providers\OllamaProvider;

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
        Route::aliasMiddleware('serverAuthentication', ExternalServerAuth::class);
        $this->app->bind('ollama.provider', function () {
            return new OllamaProvider();
        });
        if (config('model_providers') === null && config('model_provider') !== null) {
            config()->set('model_providers', config('model_provider'));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
