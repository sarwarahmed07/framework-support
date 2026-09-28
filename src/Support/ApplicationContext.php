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
     * Get clean domain name without http, https, www, slashes or port.
     * Example: "https://www.aimoni.dynv6.net:8000" => "aimoni.dynv6.net"
     */
    public function domain(): string
    {
        $rawHost = '';
        if (!$this->app->runningInConsole() && isset($this->app['request'])) {
            $rawHost = (string) $this->app['request']->getHost();
        }

        if (empty($rawHost) || $rawHost === 'localhost' || $rawHost === '127.0.0.1') {
            $url = $this->appUrl();
            $parsedHost = parse_url($url, PHP_URL_HOST);
            if (!empty($parsedHost)) {
                $rawHost = $parsedHost;
            }
        }

        if (empty($rawHost)) {
            $rawHost = 'localhost';
        }

        return self::normalizeDomain($rawHost);
    }

    /**
     * Normalize domain string by stripping protocol, www, port, and trailing slashes.
     */
    public static function normalizeDomain(string $domain): string
    {
        // Strip scheme
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        // Strip path/query
        $domain = explode('/', $domain)[0];
        // Strip port
        $domain = explode(':', $domain)[0];
        // Strip leading www.
        $domain = preg_replace('#^www\.#i', '', $domain);

        return strtolower(trim($domain));
    }

    /**
     * Convert clean domain to Firebase-safe key by replacing dots and invalid chars with underscores.
     * Example: "aimoni.dynv6.net" => "aimoni_dynv6_net"
     */
    public static function domainToKey(string $domain): string
    {
        $clean = self::normalizeDomain($domain);
        // Replace ., -, :, / and special chars with underscore
        return preg_replace('/[^a-zA-Z0-9]/', '_', $clean);
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
        $cleanDomain = $this->domain();
        return [
            'product' => $this->product(),
            'domain' => $cleanDomain,
            'domain_key' => self::domainToKey($cleanDomain),
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
