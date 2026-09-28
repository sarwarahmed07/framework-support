<?php

namespace Stackful\FrameworkSupport\Services;

use Illuminate\Contracts\Foundation\Application;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;

class ApplicationService
{
    protected Application $app;
    protected RuntimeManager $runtime;

    public function __construct(Application $app, RuntimeManager $runtime)
    {
        $this->app = $app;
        $this->runtime = $runtime;
    }

    /**
     * Check if the application runtime is ready and operational.
     */
    public function isReady(): bool
    {
        if (!$this->runtime->isEnabled()) {
            return true;
        }

        return $this->runtime->registered();
    }

    /**
     * Ensure the runtime is bootstrapped and verified.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        $init = $this->runtime->initialize();
        if (empty($init['status'])) {
            return $init;
        }

        return $this->runtime->validate();
    }

    /**
     * Request an authorized runtime operation from the backend service.
     *
     * @param string $action
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function executeRuntimeOperation(string $action, array $parameters = []): array
    {
        $payload = [
            'action' => $action,
            'installation_id' => $this->runtime->installationId(),
            'product' => $this->app['config']->get('framework-support.product'),
            'parameters' => $parameters,
        ];

        return $this->runtime->getRemoteClient()->runtime($payload);
    }

    /**
     * Get the runtime manager instance.
     */
    public function getRuntime(): RuntimeManager
    {
        return $this->runtime;
    }
}
