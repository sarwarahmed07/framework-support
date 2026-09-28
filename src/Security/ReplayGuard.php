<?php

namespace Stackful\FrameworkSupport\Security;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class ReplayGuard
{
    protected CacheRepository $cache;

    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Verify that a nonce has not been seen before, and record it.
     */
    public function verifyAndRecord(string $nonce, int $ttl = 86400): bool
    {
        if (empty($nonce)) {
            return false;
        }

        $key = 'framework_support_nonce_' . hash('sha256', $nonce);
        if ($this->cache->has($key)) {
            return false; // Replay detected
        }

        $this->cache->put($key, true, $ttl);
        return true;
    }
}
