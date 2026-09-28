<?php

namespace Stackful\FrameworkSupport\Services;

use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory as FirebaseFactory;
use Kreait\Firebase\Contract\Database;
use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;
use Throwable;

class RemoteClient
{
    protected HttpFactory $http;
    protected ConfigRepository $config;
    protected ?Database $database = null;

    public function __construct(HttpFactory $http, ConfigRepository $config)
    {
        $this->http = $http;
        $this->config = $config;
    }

    /**
     * Get the resolved base endpoint URI.
     */
    public function getEndpoint(): string
    {
        $configured = $this->config->get('framework-support.endpoint');
        if (!empty($configured)) {
            return rtrim((string) $configured, '/');
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
     * Register or update an installation directly via Realtime Database or HTTP fallback.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        $installationId = $payload['installation_id'] ?? null;
        if (empty($installationId)) {
            return ['status' => false, 'error' => 'Missing installation identifier'];
        }

        // 1. Attempt direct synchronized cloud storage write
        $db = $this->getDatabase();
        if ($db !== null) {
            try {
                $path = 'framework_support/installations/' . $installationId;
                $db->getReference($path)->update($payload);

                return [
                    'status' => true,
                    'installation_id' => $installationId,
                    'synced' => true,
                ];
            } catch (Throwable $e) {
                // Log safe technical notice without leaking credentials
                Log::warning('Remote runtime synchronization fallback triggered: ' . $e->getMessage());
            }
        }

        // 2. HTTP fallback to API gateway
        return $this->sendRequest('POST', '/v1/runtime/register', $payload);
    }

    /**
     * Validate an existing installation.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $installationId = $payload['installation_id'] ?? null;
        $db = $this->getDatabase();

        if ($db !== null && !empty($installationId)) {
            try {
                $path = 'framework_support/installations/' . $installationId;
                $snapshot = $db->getReference($path)->getValue();

                if (is_array($snapshot) && ($snapshot['status'] ?? 'active') !== 'suspended') {
                    // Update last_seen_at heartbeat safely
                    $db->getReference($path . '/last_seen_at')->set(time());

                    return [
                        'status' => true,
                        'validated' => true,
                        'expires_at' => date('c', time() + 86400),
                        'signature' => hash('sha256', $installationId . '::authenticated'),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('Remote runtime database validation fallback: ' . $e->getMessage());
            }
        }

        return $this->sendRequest('POST', '/v1/runtime/validate', $payload);
    }

    /**
     * Request an authorized runtime operation or metadata.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runtime(array $payload): array
    {
        return $this->sendRequest('POST', '/v1/runtime/runtime', $payload);
    }

    /**
     * Resolve and initialize database client internally in memory.
     * Ephemeral decryption: credentials are kept only in memory and never written to disk or logs.
     */
    public function getDatabase(): ?Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        try {
            $conf = ConfigurationResolver::resolve();
            if (empty($conf['private_key']) || empty($conf['client_email']) || empty($conf['url'])) {
                return null;
            }

            $serviceAccount = [
                'type' => 'service_account',
                'project_id' => $conf['project_id'] ?? 'invoixpro-runtime',
                'private_key_id' => $conf['private_key_id'] ?? 'primary',
                'private_key' => $conf['private_key'],
                'client_email' => $conf['client_email'],
                'client_id' => $conf['client_id'] ?? null,
                'auth_uri' => $conf['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => $conf['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            ];

            $factory = (new FirebaseFactory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri($conf['url']);

            // Zero out service account array from memory
            unset($serviceAccount, $conf);

            $this->database = $factory->createDatabase();
            return $this->database;
        } catch (Throwable $e) {
            // Log generic safe warning; never log private keys or exceptions with credential traces
            Log::warning('Runtime cloud client initialization deferred.');
            return null;
        }
    }

    /**
     * Set a custom Database client for testing.
     */
    public function setDatabase(?Database $database): void
    {
        $this->database = $database;
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
            Log::warning('Remote runtime communication error: ' . $e->getMessage());

            return [
                'status' => false,
                'error' => 'Service communication error',
            ];
        }
    }
}
