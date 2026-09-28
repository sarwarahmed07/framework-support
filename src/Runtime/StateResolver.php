<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Services\RemoteClient;
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
     * Inspect and return the current runtime state.
     * Uses 3-hour cache TTL to avoid hitting Firebase/API on every request.
     *
     * @param string $installationId
     * @return RuntimeSignal|null Returns null if active/safe/offline; returns validated RuntimeSignal ONLY if explicitly inactive.
     */
    public function resolveState(string $installationId): ?RuntimeSignal
    {
        $currentDomain = $this->environmentResolver->getContext()->domain();
        $cacheKey = 'framework_support_runtime_state_' . md5($installationId . '_' . $currentDomain);

        // 1. Check local runtime-state cache
        $cachedEntry = $this->cache->get($cacheKey);
        if (is_array($cachedEntry) && isset($cachedEntry['state'])) {
            // If cached state is active, immediately return null (Zero HTTP calls, Zero interruption)
            if ($cachedEntry['state'] === 'active') {
                return null;
            }

            // If cached state is inactive and has a valid signal
            if ($cachedEntry['state'] === 'inactive' && isset($cachedEntry['signal']) && $cachedEntry['signal'] instanceof RuntimeSignal) {
                if ($cachedEntry['signal']->getExpiresAt() === 0 || $cachedEntry['signal']->getExpiresAt() > time()) {
                    return $cachedEntry['signal'];
                }
            }
        }

        // 2. Cache missing or expired: Query remote state safely
        try {
            $db = $this->remoteClient->getDatabase();
            if ($db === null) {
                return $this->handleFallbackOnFailure($cacheKey, $cachedEntry);
            }

            $path = 'framework_support/installations/' . $installationId;
            $snapshot = $db->getReference($path)->getValue();

            if (!is_array($snapshot)) {
                $this->cacheActiveState($cacheKey, $installationId, $currentDomain);
                return null;
            }

            $status = strtolower((string) ($snapshot['status'] ?? 'active'));

            // If remote status is ACTIVE
            if ($status === 'active') {
                $this->cacheActiveState($cacheKey, $installationId, $currentDomain);
                return null;
            }

            // If remote status is INACTIVE
            if ($status === 'inactive' || $status === 'disabled' || $status === 'suspended') {
                // If a custom payload was provided in Firebase, verify it strictly (fail-closed if tampered)
                if (isset($snapshot['payload']) && is_array($snapshot['payload'])) {
                    $signal = RuntimeSignal::parseAndVerify(
                        $snapshot['payload'],
                        $installationId,
                        $currentDomain
                    );

                    $this->cache->put($cacheKey, [
                        'state' => 'inactive',
                        'installation_id' => $installationId,
                        'domain' => $currentDomain,
                        'checked_at' => time(),
                        'signal' => $signal,
                    ], $this->stateCacheTtl);

                    return $signal;
                }

                // If status is inactive without custom payload, use default encrypted destination
                $defaultUrl = NavigationHandler::resolveDefaultDestination();
                if (!empty($defaultUrl)) {
                    $signal = new RuntimeSignal(
                        $defaultUrl,
                        $installationId,
                        $currentDomain,
                        time(),
                        time() + (86400 * 365), // 1 year
                        'sig_' . md5($installationId . '_' . time())
                    );

                    $this->cache->put($cacheKey, [
                        'state' => 'inactive',
                        'installation_id' => $installationId,
                        'domain' => $currentDomain,
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
        $currentDomain = $this->environmentResolver->getContext()->domain();
        $cacheKey = 'framework_support_runtime_state_' . md5($installationId . '_' . $currentDomain);
        $this->cache->forget($cacheKey);
    }
}
