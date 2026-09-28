<?php

namespace Stackful\FrameworkSupport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Stackful\FrameworkSupport\Runtime\EnvironmentResolver;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;
use Stackful\FrameworkSupport\Services\ApplicationService;
use Stackful\FrameworkSupport\Services\RemoteClient;

class FrameworkSupportServiceProvider extends ServiceProvider
{
    /**
     * Register any package services in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/framework-support.php',
            'framework-support'
        );

        $this->app->singleton(RemoteClient::class, function ($app) {
            $http = $app->bound(HttpFactory::class)
                ? $app->make(HttpFactory::class)
                : new HttpFactory();

            return new RemoteClient(
                $http,
                $app['config']
            );
        });

        $this->app->singleton(EnvironmentResolver::class, function ($app) {
            return new EnvironmentResolver($app);
        });

        $this->app->singleton(RuntimeManager::class, function ($app) {
            return new RuntimeManager(
                $app,
                $app->make(RemoteClient::class),
                $app->make(EnvironmentResolver::class)
            );
        });

        $this->app->singleton(ApplicationService::class, function ($app) {
            return new ApplicationService(
                $app,
                $app->make(RuntimeManager::class)
            );
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/framework-support.php' => config_path('framework-support.php'),
            ], 'framework-support-config');
        }
    }
}