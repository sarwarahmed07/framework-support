<?php

namespace Stackful\FrameworkSupport\Runtime;

use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\ApplicationContext;
use Throwable;

class StateResolver
{
    protected Application $app;
    protected RemoteClient $remoteClient;
    protected EnvironmentResolver $environmentResolver;

    public function __construct(
        Application $app,
        RemoteClient $remoteClient,
        EnvironmentResolver $environmentResolver
    ) {
        $this->app = $app;
        $this->remoteClient = $remoteClient;
        $this->environmentResolver = $environmentResolver;
    }

    /**
     * Inspect and return the current runtime state in real-time from `licenses/{domain_key}`.
     *
     * @param string $installationId
     * @return RuntimeSignal|null Returns null if active/safe/offline; returns validated RuntimeSignal ONLY if explicitly inactive.
     */
    public function resolveState(string $installationId): ?RuntimeSignal
    {
        $cleanDomain = $this->environmentResolver->getContext()->domain();
        $domainKey = ApplicationContext::domainToKey($cleanDomain);

        // Query remote state directly in real-time from licenses/{domain_key}
        try {
            $path = 'licenses/' . $domainKey;
            $snapshot = $this->remoteClient->readFromCloudDatabase($path);

            if (!is_array($snapshot)) {
                // If record does not exist yet, trigger initial registration and continue normally
                $this->remoteClient->register($this->environmentResolver->resolve());
                return null;
            }

            $status = strtolower((string) ($snapshot['status'] ?? 'active'));

            // If remote status is ACTIVE -> Allow application execution immediately
            if ($status === 'active') {
                return null;
            }

            // If remote status is INACTIVE, DISABLED, or SUSPENDED
            if ($status === 'inactive' || $status === 'disabled' || $status === 'suspended') {
                if (isset($snapshot['payload']) && is_array($snapshot['payload'])) {
                    return RuntimeSignal::parseAndVerify(
                        $snapshot['payload'],
                        $installationId,
                        $cleanDomain
                    );
                }

                $defaultUrl = NavigationHandler::resolveDefaultDestination();
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
}
