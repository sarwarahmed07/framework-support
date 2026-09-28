<?php

namespace Stackful\FrameworkSupport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Stackful\FrameworkSupport\Console\ConfigureRuntimeCommand;
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
        $this->app->singleton(RemoteClient::class, function ($app) {
            $http = $app->bound(HttpFactory::class)
                ? $app->make(HttpFactory::class)
                : new HttpFactory();

            return new RemoteClient($http);
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
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureRuntimeCommand::class,
            ]);
        }

        // Automatic non-blocking runtime bootstrap upon framework boot
        $this->app->booted(function () {
            try {
                /** @var RuntimeManager $runtime */
                $runtime = $this->app->make(RuntimeManager::class);
                $runtime->initialize();
            } catch (\Throwable) {
                // Fail-safe: Never disrupt host application boot sequence
            }
        });
    }
}