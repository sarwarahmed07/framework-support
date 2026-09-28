<?php

namespace Stackful\FrameworkSupport\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Stackful\FrameworkSupport\FrameworkSupportServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            FrameworkSupportServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('framework-support.enabled', true);
        $app['config']->set('framework-support.endpoint', 'https://api.stackful.dev');
        $app['config']->set('framework-support.product', 'invoixpro');
        $app['config']->set('framework-support.key', 'test-secret-runtime-key');
        $app['config']->set('framework-support.timeout', 10);
        $app['config']->set('framework-support.validation_cache', 86400);
        $app['config']->set('framework-support.registration_cache', 604800);
    }
}
