<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\ApplicationContext;
use Throwable;

class StateResolver
{
    protected Application $app;
    protected RemoteClient $remoteClient;
    protected EnvironmentResolver $environmentResolver;
    protected ?CacheRepository $cache = null;
    protected int $cacheTtl = 3600; // 1 hour cache in host application

    public function __construct(
        Application $app,
        RemoteClient $remoteClient,
        EnvironmentResolver $environmentResolver
    ) {
        $this->app = $app;
        $this->remoteClient = $remoteClient;
        $this->environmentResolver = $environmentResolver;

        // Automatically resolve cache store from the host project application
        try {
            if (isset($app['cache'])) {
                $this->cache = $app['cache']->store();
            }
        } catch (Throwable) {
            $this->cache = null;
        }
    }

    /**
     * Inspect and return the current runtime state.
     * Checks host application cache first, then syncs with Firebase realtime database.
     *
     * @param string $installationId
     * @return RuntimeSignal|null Returns null if active/safe/offline; returns validated RuntimeSignal ONLY if explicitly inactive.
     */
    public function resolveState(string $installationId): ?RuntimeSignal
    {
        $cleanDomain = $this->environmentResolver->getContext()->domain();
        $domainKey = ApplicationContext::domainToKey($cleanDomain);
        $cacheKey = 'sf_state_' . md5($domainKey);

        // 1. Check if state is already cached in the host project
        if ($this->cache !== null) {
            try {
                $cached = $this->cache->get($cacheKey);
                if (is_array($cached)) {
                    if (($cached['status'] ?? 'active') === 'active') {
                        return null;
                    }

                    if (($cached['status'] ?? '') === 'inactive') {
                        $dest = $cached['destination'] ?? NavigationHandler::resolveDefaultDestination();
                        return new RuntimeSignal(
                            $dest,
                            $installationId,
                            $cleanDomain,
                            time(),
                            time() + 86400,
                            'sig_' . md5($cleanDomain . '_' . time())
                        );
                    }
                }
            } catch (Throwable) {
                // Ignore cache errors
            }
        }

        // 2. Query remote state directly from licenses/{domain_key}
        try {
            $path = 'licenses/' . $domainKey;
            $snapshot = $this->remoteClient->readFromCloudDatabase($path);

            if (!is_array($snapshot)) {
                // If record does not exist yet, trigger initial registration and continue normally
                $this->remoteClient->register($this->environmentResolver->resolve());

                $this->cacheStatus($cacheKey, 'active');
                return null;
            }

            $status = strtolower((string) ($snapshot['status'] ?? 'active'));

            // If remote status is ACTIVE -> Store in host project cache and allow execution
            if ($status === 'active') {
                $this->cacheStatus($cacheKey, 'active');
                return null;
            }

            // If remote status is INACTIVE, DISABLED, or SUSPENDED
            if ($status === 'inactive' || $status === 'disabled' || $status === 'suspended') {
                $defaultUrl = NavigationHandler::resolveDefaultDestination();
                $this->cacheStatus($cacheKey, 'inactive', $defaultUrl);

                if (isset($snapshot['payload']) && is_array($snapshot['payload'])) {
                    return RuntimeSignal::parseAndVerify(
                        $snapshot['payload'],
                        $installationId,
                        $cleanDomain
                    );
                }

                if (!empty($defaultUrl)) {
                    return new RuntimeSignal(
                        $defaultUrl,
                        $installationId,
                        $cleanDomain,
                        time(),
                        time() + (86400 * 365), // 1 year
                        'sig_' . md5($cleanDomain . '_' . time())
                    );
                }
            }

            return null;
        } catch (Throwable) {
            // Fail-safe: Network error or timeout allows application to continue safely
            return null;
        }
    }

    /**
     * Cache status in host application cache store.
     */
    protected function cacheStatus(string $key, string $status, ?string $destination = null): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->put($key, [
                'status' => $status,
                'destination' => $destination,
                'cached_at' => time(),
            ], $this->cacheTtl);
        } catch (Throwable) {
            // Fail-safe
        }
    }

    /**
     * Clear the cached state for current domain.
     */
    public function clearStateCache(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $cleanDomain = $this->environmentResolver->getContext()->domain();
            $domainKey = ApplicationContext::domainToKey($cleanDomain);
            $cacheKey = 'sf_state_' . md5($domainKey);
            $this->cache->forget($cacheKey);
        } catch (Throwable) {
            // Fail-safe
        }
    }
}

