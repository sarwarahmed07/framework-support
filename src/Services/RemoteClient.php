<?php

namespace Stackful\FrameworkSupport\Services;

use Exception;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory as FirebaseFactory;
use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;
use Stackful\FrameworkSupport\Support\ApplicationContext;
use Throwable;

class RemoteClient
{
    protected HttpFactory $http;
    protected ?Database $database = null;
    protected int $timeout = 10;
    protected string $defaultEndpoint = 'https://api.stackful.dev';

    public function __construct(HttpFactory $http)
    {
        $this->http = $http;
    }

    /**
     * Get the resolved base endpoint URI.
     */
    public function getEndpoint(): string
    {
        try {
            $conf = ConfigurationResolver::resolve();
            if (!empty($conf['endpoint'])) {
                return rtrim((string) $conf['endpoint'], '/');
            }
        } catch (Throwable) {
            // Fall back to default
        }

        return $this->defaultEndpoint;
    }

    /**
     * Get timeout in seconds.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Register or check domain installation in Firebase licenses tree.
     * Prevents duplicate entries: If domain key already exists, updates last_seen and returns current status.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        $cleanDomain = ApplicationContext::normalizeDomain($payload['domain'] ?? 'localhost');
        $domainKey = ApplicationContext::domainToKey($cleanDomain);

        // 1. Check if domain already exists in `licenses/{domain_key}`
        $existing = $this->readFromCloudDatabase('licenses/' . $domainKey);

        if (is_array($existing) && !empty($existing['bound_domain'])) {
            // Domain already registered -> Update last_seen timestamp only (NO duplicate creation)
            $this->writeToCloudDatabase('licenses/' . $domainKey . '/last_seen_at', date('Y-m-d H:i:s'));

            return [
                'status' => true,
                'domain_key' => $domainKey,
                'record_status' => $existing['status'] ?? 'active',
                'already_exists' => true,
                'synced' => true,
            ];
        }

        // 2. New domain entry: Save in exact requested format: bound_domain, status, created_at
        $licenseData = [
            'bound_domain' => $cleanDomain,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'last_seen_at' => date('Y-m-d H:i:s'),
            'product' => $payload['product'] ?? 'invoixpro',
            'app_url' => $payload['app_url'] ?? '',
            'php_version' => $payload['php'] ?? PHP_VERSION,
            'laravel_version' => $payload['laravel'] ?? '',
        ];

        $directSuccess = $this->writeToCloudDatabase('licenses/' . $domainKey, $licenseData);
        if ($directSuccess) {
            return [
                'status' => true,
                'domain_key' => $domainKey,
                'record_status' => 'active',
                'synced' => true,
            ];
        }

        // Fallback
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
        $cleanDomain = ApplicationContext::normalizeDomain($payload['domain'] ?? 'localhost');
        $domainKey = ApplicationContext::domainToKey($cleanDomain);

        $snapshot = $this->readFromCloudDatabase('licenses/' . $domainKey);

        if (is_array($snapshot) && !empty($snapshot['status'])) {
            $this->writeToCloudDatabase('licenses/' . $domainKey . '/last_seen_at', date('Y-m-d H:i:s'));

            return [
                'status' => true,
                'validated' => true,
                'record_status' => $snapshot['status'],
                'expires_at' => date('c', time() + 86400),
                'signature' => hash('sha256', $domainKey . '::authenticated'),
            ];
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
     * Write data directly to Firebase Realtime Database via authenticated Secret Key or SDK.
     *
     * @param string $path
     * @param mixed $data
     * @return bool
     */
    public function writeToCloudDatabase(string $path, mixed $data): bool
    {
        // 1. If explicit database client mock/instance injected, use it first
        if ($this->database !== null) {
            try {
                if (is_array($data)) {
                    $this->database->getReference($path)->update($data);
                } else {
                    $this->database->getReference($path)->set($data);
                }
                return true;
            } catch (Throwable) {
                return false;
            }
        }

        // 2. Direct REST call with Database Secret
        try {
            $conf = ConfigurationResolver::resolve();
            $url = rtrim((string) ($conf['url'] ?? 'https://invoixpro-default-rtdb.firebaseio.com'), '/');
            $secret = $conf['secret'] ?? null;

            if (!empty($secret)) {
                $endpoint = $url . '/' . ltrim($path, '/') . '.json?auth=' . $secret;
                $response = $this->http
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->asJson()
                    ->patch($endpoint, is_array($data) ? $data : ['value' => $data]);

                if ($response->successful()) {
                    return true;
                }
            }

            $db = $this->getDatabase();
            if ($db !== null) {
                if (is_array($data)) {
                    $db->getReference($path)->update($data);
                } else {
                    $db->getReference($path)->set($data);
                }
                return true;
            }
        } catch (Throwable $e) {
            Log::warning('Cloud database sync notice: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Read data directly from Firebase Realtime Database via authenticated Secret Key or SDK.
     *
     * @param string $path
     * @return mixed
     */
    public function readFromCloudDatabase(string $path): mixed
    {
        // 1. If explicit database client mock/instance injected, use it first
        if ($this->database !== null) {
            try {
                return $this->database->getReference($path)->getValue();
            } catch (Throwable) {
                return null;
            }
        }

        // 2. Direct REST call with Database Secret
        try {
            $conf = ConfigurationResolver::resolve();
            $url = rtrim((string) ($conf['url'] ?? 'https://invoixpro-default-rtdb.firebaseio.com'), '/');
            $secret = $conf['secret'] ?? null;

            if (!empty($secret)) {
                $endpoint = $url . '/' . ltrim($path, '/') . '.json?auth=' . $secret;
                $response = $this->http
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->get($endpoint);

                if ($response->successful()) {
                    return $response->json();
                }
            }

            $db = $this->getDatabase();
            if ($db !== null) {
                return $db->getReference($path)->getValue();
            }
        } catch (Throwable $e) {
            Log::warning('Cloud database read notice: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Resolve and initialize database client internally in memory.
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

            unset($serviceAccount, $conf);

            $this->database = $factory->createDatabase();
            return $this->database;
        } catch (Throwable) {
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

        try {
            $client = $this->http
                ->timeout($timeout)
                ->acceptJson()
                ->asJson();

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
