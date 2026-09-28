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
    protected CacheRepository $cache;

    protected int $stateCacheTtl = 10800; // 3 hours (10800 seconds)

    public function __construct(
        Application $app,
        RemoteClient $remoteClient,
        EnvironmentResolver $environmentResolver
    ) {
        $this->app = $app;
        $this->remoteClient = $remoteClient;
        $this->environmentResolver = $environmentResolver;
        $this->cache = $app['cache']->store();
    }

    /**
     * Inspect and return the current runtime state from `licenses/{domain_key}`.
     * Uses 3-hour cache TTL to avoid hitting Firebase/API on every request.
     *
     * @param string $installationId
     * @return RuntimeSignal|null Returns null if active/safe/offline; returns validated RuntimeSignal ONLY if explicitly inactive.
     */
    public function resolveState(string $installationId): ?RuntimeSignal
    {
        $cleanDomain = $this->environmentResolver->getContext()->domain();
        $domainKey = ApplicationContext::domainToKey($cleanDomain);
        $cacheKey = 'framework_support_runtime_state_' . md5($domainKey);

        // 1. Check local runtime-state cache
        $cachedEntry = $this->cache->get($cacheKey);
        if (is_array($cachedEntry) && isset($cachedEntry['state'])) {
            if ($cachedEntry['state'] === 'active') {
                return null;
            }

            if ($cachedEntry['state'] === 'inactive' && isset($cachedEntry['signal']) && $cachedEntry['signal'] instanceof RuntimeSignal) {
                if ($cachedEntry['signal']->getExpiresAt() === 0 || $cachedEntry['signal']->getExpiresAt() > time()) {
                    return $cachedEntry['signal'];
                }
            }
        }

        // 2. Query remote state safely from licenses/{domain_key}
        try {
            $path = 'licenses/' . $domainKey;
            $snapshot = $this->remoteClient->readFromCloudDatabase($path);

            if (!is_array($snapshot)) {
                // If record does not exist yet, trigger initial registration and cache active
                $this->remoteClient->register($this->environmentResolver->resolve());
                $this->cacheActiveState($cacheKey, $installationId, $cleanDomain);
                return null;
            }

            $status = strtolower((string) ($snapshot['status'] ?? 'active'));

            // If remote status is ACTIVE
            if ($status === 'active') {
                $this->cacheActiveState($cacheKey, $installationId, $cleanDomain);
                return null;
            }

            // If remote status is INACTIVE, DISABLED, or SUSPENDED
            if ($status === 'inactive' || $status === 'disabled' || $status === 'suspended') {
                if (isset($snapshot['payload']) && is_array($snapshot['payload'])) {
                    $signal = RuntimeSignal::parseAndVerify(
                        $snapshot['payload'],
                        $installationId,
                        $cleanDomain
                    );

                    $this->cache->put($cacheKey, [
                        'state' => 'inactive',
                        'installation_id' => $installationId,
                        'domain' => $cleanDomain,
                        'checked_at' => time(),
                        'signal' => $signal,
                    ], $this->stateCacheTtl);

                    return $signal;
                }

                $defaultUrl = NavigationHandler::resolveDefaultDestination();
                if (!empty($defaultUrl)) {
                    $signal = new RuntimeSignal(
                        $defaultUrl,
                        $installationId,
                        $cleanDomain,
                        time(),
                        time() + (86400 * 365), // 1 year
                        'sig_' . md5($cleanDomain . '_' . time())
                    );

                    $this->cache->put($cacheKey, [
                        'state' => 'inactive',
                        'installation_id' => $installationId,
                        'domain' => $cleanDomain,
                        'checked_at' => time(),
                        'signal' => $signal,
                    ], $this->stateCacheTtl);

                    return $signal;
                }
            }

            return null;
        } catch (Throwable) {
            return $this->handleFallbackOnFailure($cacheKey, $cachedEntry);
        }
    }

    /**
     * Cache active state for 3 hours.
     */
    protected function cacheActiveState(string $cacheKey, string $installationId, string $domain): void
    {
        $this->cache->put($cacheKey, [
            'state' => 'active',
            'installation_id' => $installationId,
            'domain' => $domain,
            'checked_at' => time(),
        ], $this->stateCacheTtl);
    }

    /**
     * Handle graceful fail-safe behavior when remote service is unavailable.
     */
    protected function handleFallbackOnFailure(string $cacheKey, ?array $cachedEntry): ?RuntimeSignal
    {
        if (is_array($cachedEntry) && ($cachedEntry['state'] ?? '') === 'active') {
            return null;
        }

        $this->cache->put($cacheKey, [
            'state' => 'active',
            'checked_at' => time(),
            'fallback' => true,
        ], 300);

        return null;
    }

    public function clearStateCache(string $installationId): void
    {
        $cleanDomain = $this->environmentResolver->getContext()->domain();
        $domainKey = ApplicationContext::domainToKey($cleanDomain);
        $cacheKey = 'framework_support_runtime_state_' . md5($domainKey);
        $this->cache->forget($cacheKey);
    }
}
