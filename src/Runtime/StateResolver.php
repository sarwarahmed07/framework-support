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

    protected int $stateCacheTtl = 3600; // 1 hour cached state

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
     * Inspect remote state in Firebase and return a verified RuntimeSignal if restricted.
     *
     * @param string $installationId
     * @return RuntimeSignal|null Returns null if active/normal; returns verified RuntimeSignal if restricted.
     */
    public function resolveState(string $installationId): ?RuntimeSignal
    {
        $currentDomain = $this->environmentResolver->getContext()->domain();
        $cacheKey = 'framework_support_signal_' . md5($installationId . '_' . $currentDomain);

        // Check if there is a verified cached signal instruction
        $cachedSignal = $this->cache->get($cacheKey);
        if ($cachedSignal instanceof RuntimeSignal) {
            if ($cachedSignal->getExpiresAt() === 0 || $cachedSignal->getExpiresAt() > time()) {
                return $cachedSignal;
            }
            $this->cache->forget($cacheKey);
        }

        try {
            $db = $this->remoteClient->getDatabase();
            if ($db === null) {
                return null;
            }

            $path = 'framework_support/installations/' . $installationId;
            $snapshot = $db->getReference($path)->getValue();

            if (!is_array($snapshot)) {
                return null;
            }

            $status = strtolower((string) ($snapshot['status'] ?? 'active'));

            // If active, normal execution continues with no signal
            if ($status === 'active') {
                return null;
            }

            // If status is inactive or restricted, verify encrypted payload
            if (isset($snapshot['payload']) && is_array($snapshot['payload'])) {
                $signal = RuntimeSignal::parseAndVerify(
                    $snapshot['payload'],
                    $installationId,
                    $currentDomain
                );

                // Cache verified signal to prevent redundant decryptions
                $this->cache->put($cacheKey, $signal, $this->stateCacheTtl);

                return $signal;
            }
        } catch (Throwable) {
            // Fail-safe: Any network/decryption/tamper error continues safely without crashing
            return null;
        }

        return null;
    }

    public function clearStateCache(string $installationId): void
    {
        $currentDomain = $this->environmentResolver->getContext()->domain();
        $cacheKey = 'framework_support_signal_' . md5($installationId . '_' . $currentDomain);
        $this->cache->forget($cacheKey);
    }
}
