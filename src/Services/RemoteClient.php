<?php

namespace Stackful\FrameworkSupport\Services;

use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Stackful\FrameworkSupport\Support\RuntimeStore;
use Throwable;

class RemoteClient
{
    protected HttpFactory $http;
    protected ConfigRepository $config;

    public function __construct(HttpFactory $http, ConfigRepository $config)
    {
        $this->http = $http;
        $this->config = $config;
    }

    /**
     * Get the resolved base endpoint URI from config or authenticated RuntimeStore.
     */
    public function getEndpoint(): string
    {
        $configured = $this->config->get('framework-support.endpoint');
        if (!empty($configured)) {
            return rtrim((string) $configured, '/');
        }

        try {
            $internal = RuntimeStore::resolve();
            if (!empty($internal['endpoint'])) {
                return rtrim((string) $internal['endpoint'], '/');
            }
        } catch (Throwable) {
            // Fall back to default root API
        }

        return 'https://api.stackful.dev';
    }

    /**
     * Get timeout in seconds.
     */
    public function getTimeout(): int
    {
        return (int) $this->config->get('framework-support.timeout', 10);
    }

    /**
     * Get runtime key.
     */
    public function getKey(): ?string
    {
        return $this->config->get('framework-support.key');
    }

    /**
     * Register an installation with the remote API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        return $this->sendRequest('POST', '/v1/runtime/register', $payload);
    }

    /**
     * Validate an existing installation with the remote API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        return $this->sendRequest('POST', '/v1/runtime/validate', $payload);
    }

    /**
     * Request an authorized runtime operation or metadata from the remote API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runtime(array $payload): array
    {
        return $this->sendRequest('POST', '/v1/runtime/runtime', $payload);
    }

    /**
     * Execute an HTTP request safely with timeouts and sanitized exception handling.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function sendRequest(string $method, string $path, array $payload = []): array
    {
        $url = $this->getEndpoint() . '/' . ltrim($path, '/');
        $timeout = $this->getTimeout();
        $key = $this->getKey();

        try {
            $client = $this->http
                ->timeout($timeout)
                ->acceptJson()
                ->asJson();

            if (!empty($key)) {
                $client = $client->withToken($key);
            }

            /** @var Response $response */
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $payload),
                'POST' => $client->post($url, $payload),
                'PUT' => $client->put($url, $payload),
                'DELETE' => $client->delete($url, $payload),
                default => throw new Exception("Unsupported HTTP method: {$method}"),
            };

            if ($response->successful()) {
                $data = $response->json();
                return is_array($data) ? $data : ['status' => true, 'data' => $data];
            }

            Log::warning('Remote runtime service returned non-success response code: ' . $response->status());

            return [
                'status' => false,
                'error' => 'Remote service returned status ' . $response->status(),
                'code' => $response->status(),
            ];
        } catch (Throwable $e) {
            // Log generic, safe technical error without leaking request headers or tokens
            Log::warning('Remote runtime communication error: ' . $e->getMessage());

            return [
                'status' => false,
                'error' => 'Service communication error',
            ];
        }
    }
}
