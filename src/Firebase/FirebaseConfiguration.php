<?php

namespace Stackful\FrameworkSupport\Firebase;

use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;

class FirebaseConfiguration
{
    /**
     * Resolve configuration safely in-memory without persistent disk leakage.
     *
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        return ConfigurationResolver::resolve();
    }
}
