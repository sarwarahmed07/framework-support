<?php

namespace Stackful\FrameworkSupport\Support;

use Illuminate\Contracts\Foundation\Application;

class ApplicationContext
{
    protected Application $app;
    protected string $packageVersion;
    protected string $product;

    public function __construct(Application $app, string $packageVersion = '1.0.0', string $product = 'invoixpro')
    {
        $this->app = $app;
        $this->packageVersion = $packageVersion;
        $this->product = $product;
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

        if (isset($this->app['request'])) {
            return rtrim((string) $this->app['request']->root(), '/');
        }

        return 'http://localhost';
    }

    /**
     * Get the application host domain without scheme or port.
     */
    public function domain(): string
    {
        $url = $this->appUrl();
        $host = parse_url($url, PHP_URL_HOST);

        if (!empty($host) && $host !== 'localhost') {
            return (string) $host;
        }

        if (!$this->app->runningInConsole() && isset($this->app['request'])) {
            $reqHost = (string) $this->app['request']->getHost();
            if (!empty($reqHost)) {
                return $reqHost;
            }
        }

        return !empty($host) ? (string) $host : 'localhost';
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
     * Get the hardcoded self-contained product identifier.
     */
    public function product(): string
    {
        return $this->product;
    }

    /**
     * Get application environment name (production, staging, local, etc.).
     */
    public function environment(): string
    {
        return $this->app->environment();
    }

    /**
     * Get OS description.
     */
    public function os(): string
    {
        return PHP_OS_FAMILY . ' (' . PHP_OS . ')';
    }

    /**
     * Get machine hostname.
     */
    public function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }

    /**
     * Get application timezone.
     */
    public function timezone(): string
    {
        return (string) $this->app['config']->get('app.timezone', date_default_timezone_get());
    }

    /**
     * Export non-sensitive technical metadata.
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
            'os' => $this->os(),
            'hostname' => $this->hostname(),
            'environment' => $this->environment(),
            'timezone' => $this->timezone(),
        ];
    }
}
