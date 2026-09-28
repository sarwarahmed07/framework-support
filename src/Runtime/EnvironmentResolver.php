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
     * Resolve and return non-sensitive environment and application metadata.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return [
            'product' => $this->context->product(),
            'domain' => $this->context->domain(),
            'app_url' => $this->context->appUrl(),
            'php' => $this->context->phpVersion(),
            'laravel' => $this->context->laravelVersion(),
            'package_version' => $this->context->packageVersion(),
            'environment' => $this->context->environment(),
        ];
    }

    /**
     * Return the underlying ApplicationContext instance.
     */
    public function getContext(): ApplicationContext
    {
        return $this->context;
    }
}
