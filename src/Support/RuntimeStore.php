<?php

namespace Stackful\FrameworkSupport\Support;

use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;

/**
 * Proxy support class pointing to ConfigurationResolver for backward compatibility.
 */
final class RuntimeStore
{
    /**
     * Resolve configuration envelope.
     *
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        return ConfigurationResolver::resolve();
    }

    /**
     * Verify envelope integrity.
     */
    public static function verifyIntegrity(): bool
    {
        return ConfigurationResolver::verify();
    }
}
