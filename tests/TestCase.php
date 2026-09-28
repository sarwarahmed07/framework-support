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
}
