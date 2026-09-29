<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\RuntimeHelper;

class RuntimeManager
{
    protected Application $app;
    protected RemoteClient $remoteClient;
    protected EnvironmentResolver $environmentResolver;

    protected string $storagePath;
    protected ?string $cachedInstallationId = null;

    public function __construct(
        Application $app,
        RemoteClient $remoteClient,
        EnvironmentResolver $environmentResolver
    ) {
        $this->app = $app;
        $this->remoteClient = $remoteClient;
        $this->environmentResolver = $environmentResolver;
        $this->storagePath = $app->storagePath('framework/support_id');
    }

    /**
     * Determine whether framework support is active.
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

        // 1. Check in local persistent storage file
        if (file_exists($this->storagePath)) {
            $content = trim((string) @file_get_contents($this->storagePath));
            if (RuntimeHelper::isValidUuid($content)) {
                $this->cachedInstallationId = $content;
                return $content;
            }
        }

        // 2. Generate new persistent installation UUID
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
        $envData = $this->environmentResolver->resolve();
        $payload = array_merge($envData, [
            'installation_id' => $installationId,
            'installed_at' => time(),
            'last_seen_at' => time(),
        ]);

        $response = $this->remoteClient->register($payload);

        if (!empty($response['status'])) {
            $this->persistInstallationId($installationId);

            return [
                'status' => true,
                'installation_id' => $installationId,
                'token' => $response['token'] ?? null,
                'synced' => $response['synced'] ?? false,
            ];
        }

        return [
            'status' => false,
            'installation_id' => $installationId,
            'error' => $response['error'] ?? 'Registration could not be completed',
        ];
    }

    /**
     * Validate the current runtime installation.
     *
     * @param bool $force
     * @return array<string, mixed>
     */
    public function validate(bool $force = false): array
    {
        $payload = [
            'installation_id' => $this->installationId(),
            'domain' => $this->environmentResolver->getContext()->domain(),
            'product' => $this->environmentResolver->getContext()->product(),
        ];

        $response = $this->remoteClient->validate($payload);

        if (!empty($response['status'])) {
            return [
                'status' => true,
                'validated' => true,
                'expires_at' => $response['expires_at'] ?? null,
                'signature' => $response['signature'] ?? null,
            ];
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
