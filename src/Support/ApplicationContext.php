<?php

namespace Stackful\FrameworkSupport\Support;

use Illuminate\Contracts\Foundation\Application;

class ApplicationContext
{
    protected Application $app;
    protected string $packageVersion;

    public function __construct(Application $app, string $packageVersion = '1.0.0')
    {
        $this->app = $app;
        $this->packageVersion = $packageVersion;
    }

    /**
     * Get the configured or detected application URL.
     */
    public function appUrl(): string
    {
        $configured = (string) $this->app['config']->get('app.url', '');

        if (!empty($configured) && $configured !== 'http://localhost') {
            return rtrim($configured, '/');
        }

        if ($this->app->runningInConsole()) {
            return !empty($configured) ? rtrim($configured, '/') : 'http://localhost';
        }

        return rtrim((string) $this->app['request']->root(), '/');
    }

    /**
     * Get the application host domain without scheme or port.
     */
    public function domain(): string
    {
        $url = $this->appUrl();
        $host = parse_url($url, PHP_URL_HOST);

        if (!empty($host)) {
            return (string) $host;
        }

        if (!$this->app->runningInConsole() && isset($this->app['request'])) {
            return (string) $this->app['request']->getHost();
        }

        return 'localhost';
    }

    /**
     * Get the current Laravel framework version.
     */
    public function laravelVersion(): string
    {
        return $this->app->version();
    }

    /**
     * Get the current PHP runtime version.
     */
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Get the package version.
     */
    public function packageVersion(): string
    {
        return $this->packageVersion;
    }

    /**
     * Get the product identifier.
     */
    public function product(): ?string
    {
        return $this->app['config']->get('framework-support.product');
    }

    /**
     * Get the runtime key.
     */
    public function key(): ?string
    {
        return $this->app['config']->get('framework-support.key');
    }

    /**
     * Get application environment name (production, staging, local, etc.).
     */
    public function environment(): string
    {
        return $this->app->environment();
    }

    /**
     * Export all normalized application context as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => $this->product(),
            'domain' => $this->domain(),
            'app_url' => $this->appUrl(),
            'php' => $this->phpVersion(),
            'laravel' => $this->laravelVersion(),
            'package_version' => $this->packageVersion(),
            'environment' => $this->environment(),
        ];
    }
}
