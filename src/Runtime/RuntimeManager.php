<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\RuntimeHelper;

class RuntimeManager
{
    protected Application $app;
    protected RemoteClient $remoteClient;
    protected EnvironmentResolver $environmentResolver;
    protected CacheRepository $cache;

    protected string $storagePath;
    protected ?string $cachedInstallationId = null;

    protected int $validationCacheTtl = 86400; // 24 hours
    protected int $registrationCacheTtl = 604800; // 7 days

    public function __construct(
        Application $app,
        RemoteClient $remoteClient,
        EnvironmentResolver $environmentResolver
    ) {
        $this->app = $app;
        $this->remoteClient = $remoteClient;
        $this->environmentResolver = $environmentResolver;
        $this->cache = $app['cache']->store();
        $this->storagePath = $app->storagePath('framework/support_id');
    }

    /**
     * Determine whether framework support is active (always true for self-contained package).
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Get or generate the unique installation ID for this application.
     */
    public function installationId(): string
    {
        if ($this->cachedInstallationId !== null) {
            return $this->cachedInstallationId;
        }

        // 1. Check in cache
        $cached = $this->cache->get('framework_support_installation_id');
        if (!empty($cached) && is_string($cached)) {
            $this->cachedInstallationId = $cached;
            return $cached;
        }

        // 2. Check in local persistent storage file
        if (file_exists($this->storagePath)) {
            $content = trim((string) @file_get_contents($this->storagePath));
            if (RuntimeHelper::isValidUuid($content)) {
                $this->cachedInstallationId = $content;
                $this->cache->put('framework_support_installation_id', $content, $this->registrationCacheTtl);
                return $content;
            }
        }

        // 3. Generate new persistent installation UUID
        $newId = RuntimeHelper::generateUuid();
        $this->persistInstallationId($newId);
        $this->cachedInstallationId = $newId;

        return $newId;
    }

    /**
     * Determine whether this application installation is registered.
     */
    public function registered(): bool
    {
        $isCachedRegistered = $this->cache->get('framework_support_registered');
        if ($isCachedRegistered === true) {
            return true;
        }

        return file_exists($this->storagePath);
    }

    /**
     * Automatically initialize the runtime and register technical metadata with Firebase.
     *
     * @return array<string, mixed>
     */
    public function initialize(): array
    {
        $installationId = $this->installationId();
        $regCacheKey = 'framework_support_registered';

        // Check if already registered and cached
        if ($this->cache->get($regCacheKey) === true) {
            return [
                'status' => true,
                'installation_id' => $installationId,
                'cached' => true,
            ];
        }

        $envData = $this->environmentResolver->resolve();
        $payload = array_merge($envData, [
            'installation_id' => $installationId,
            'installed_at' => time(),
            'last_seen_at' => time(),
        ]);

        $response = $this->remoteClient->register($payload);

        if (!empty($response['status'])) {
            if (!empty($response['installation_id']) && is_string($response['installation_id'])) {
                $this->persistInstallationId($response['installation_id']);
                $installationId = $response['installation_id'];
            }

            $this->cache->put($regCacheKey, true, $this->registrationCacheTtl);

            return [
                'status' => true,
                'installation_id' => $installationId,
                'token' => $response['token'] ?? null,
                'synced' => $response['synced'] ?? false,
            ];
        }

        // Graceful non-blocking failure
        return [
            'status' => false,
            'installation_id' => $installationId,
            'error' => $response['error'] ?? 'Registration could not be completed',
        ];
    }

    /**
     * Validate the current runtime installation against the remote service with caching.
     *
     * @param bool $force
     * @return array<string, mixed>
     */
    public function validate(bool $force = false): array
    {
        $cacheKey = 'framework_support_validated';

        if (!$force) {
            $cachedValidation = $this->cache->get($cacheKey);
            if ($cachedValidation !== null && is_array($cachedValidation)) {
                return $cachedValidation;
            }
        }

        $payload = [
            'installation_id' => $this->installationId(),
            'domain' => $this->environmentResolver->getContext()->domain(),
            'product' => $this->environmentResolver->getContext()->product(),
        ];

        $response = $this->remoteClient->validate($payload);

        if (!empty($response['status'])) {
            $result = [
                'status' => true,
                'validated' => true,
                'expires_at' => $response['expires_at'] ?? null,
                'signature' => $response['signature'] ?? null,
            ];

            $this->cache->put($cacheKey, $result, $this->validationCacheTtl);

            return $result;
        }

        return [
            'status' => false,
            'validated' => false,
            'error' => $response['error'] ?? 'Runtime validation failed.',
        ];
    }

    /**
     * Persist installation ID locally.
     */
    protected function persistInstallationId(string $id): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->storagePath, $id);
        $this->cache->put('framework_support_installation_id', $id, $this->registrationCacheTtl);
        $this->cachedInstallationId = $id;
    }

    /**
     * Get underlying RemoteClient.
     */
    public function getRemoteClient(): RemoteClient
    {
        return $this->remoteClient;
    }

    /**
     * Get underlying EnvironmentResolver.
     */
    public function getEnvironmentResolver(): EnvironmentResolver
    {
        return $this->environmentResolver;
    }
}
