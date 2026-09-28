<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Support\ApplicationContext;

class EnvironmentResolver
{
    protected ApplicationContext $context;

    public function __construct(Application $app, string $packageVersion = '1.0.0')
    {
        $this->context = new ApplicationContext($app, $packageVersion);
    }

    /**
     * Resolve and return non-sensitive technical environment metadata.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return $this->context->toArray();
    }

    /**
     * Return the underlying ApplicationContext instance.
     */
    public function getContext(): ApplicationContext
    {
        return $this->context;
    }
}
