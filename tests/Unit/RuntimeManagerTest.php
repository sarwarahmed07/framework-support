<?php

namespace Stackful\FrameworkSupport\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\Snapshot;
use Mockery;
use RuntimeException;
use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;
use Stackful\FrameworkSupport\Runtime\EnvironmentResolver;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\ApplicationContext;
use Stackful\FrameworkSupport\Support\RuntimeHelper;
use Stackful\FrameworkSupport\Support\RuntimeStore;
use Stackful\FrameworkSupport\Tests\TestCase;

class RuntimeManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        $storagePath = $this->app->storagePath('framework/support_id');
        if (file_exists($storagePath)) {
            @unlink($storagePath);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_runtime_manager_resolves_as_singleton(): void
    {
        $manager1 = $this->app->make(RuntimeManager::class);
        $manager2 = $this->app->make(RuntimeManager::class);

        $this->assertInstanceOf(RuntimeManager::class, $manager1);
        $this->assertSame($manager1, $manager2);
    }

    public function test_remote_client_resolves_as_singleton(): void
    {
        $client1 = $this->app->make(RemoteClient::class);
        $client2 = $this->app->make(RemoteClient::class);

        $this->assertInstanceOf(RemoteClient::class, $client1);
        $this->assertSame($client1, $client2);
    }

    public function test_environment_resolver_collects_safe_data(): void
    {
        /** @var EnvironmentResolver $resolver */
        $resolver = $this->app->make(EnvironmentResolver::class);
        $data = $resolver->resolve();

        $this->assertArrayHasKey('product', $data);
        $this->assertArrayHasKey('domain', $data);
        $this->assertArrayHasKey('domain_key', $data);
        $this->assertArrayHasKey('app_url', $data);
        $this->assertArrayHasKey('php', $data);
        $this->assertArrayHasKey('laravel', $data);
        $this->assertArrayHasKey('package_version', $data);
        $this->assertArrayHasKey('environment', $data);
        $this->assertArrayHasKey('os', $data);
        $this->assertArrayHasKey('hostname', $data);
        $this->assertArrayHasKey('timezone', $data);

        $this->assertEquals('invoixpro', $data['product']);
        $this->assertEquals('1.0.0', $data['package_version']);

        // Assert sensitive customer information is NEVER collected
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('db_password', $data);
        $this->assertArrayNotHasKey('cookie', $data);
        $this->assertArrayNotHasKey('session', $data);
        $this->assertArrayNotHasKey('invoices', $data);
        $this->assertArrayNotHasKey('payment', $data);
    }

    public function test_installation_id_generation_and_persistence(): void
    {
        /** @var RuntimeManager $runtime */
        $runtime = $this->app->make(RuntimeManager::class);

        $id1 = $runtime->installationId();
        $this->assertTrue(RuntimeHelper::isValidUuid($id1));

        $id2 = $runtime->installationId();
        $this->assertEquals($id1, $id2);
    }

    public function test_remote_client_register_request_http_fallback(): void
    {
        Http::fake([
            'https://invoixpro-default-rtdb.firebaseio.com/*' => Http::response(null, 500),
            'https://api.stackful.dev/v1/runtime/register' => Http::response([
                'status' => true,
                'installation_id' => '11111111-2222-4333-8444-555555555555',
                'token' => 'test-short-lived-token',
            ], 200),
        ]);

        /** @var RemoteClient $client */
        $client = $this->app->make(RemoteClient::class);
        $response = $client->register(['domain' => 'localhost', 'product' => 'invoixpro']);

        $this->assertTrue($response['status']);
    }

    public function test_direct_cloud_database_registration(): void
    {
        $mockRef = Mockery::mock(Reference::class);
        $mockRef->shouldReceive('getValue')->once()->andReturn(null);
        $mockRef->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function ($payload) {
                return $payload['bound_domain'] === 'localhost'
                    && $payload['status'] === 'active';
            }))
            ->andReturn($mockRef);

        $mockDb = Mockery::mock(Database::class);
        $mockDb->shouldReceive('getReference')
            ->with('licenses/localhost')
            ->twice()
            ->andReturn($mockRef);

        /** @var RemoteClient $client */
        $client = $this->app->make(RemoteClient::class);
        $client->setDatabase($mockDb);

        $res = $client->register([
            'domain' => 'localhost',
            'product' => 'invoixpro',
        ]);

        $this->assertTrue($res['status']);
        $this->assertTrue($res['synced']);
    }

    public function test_direct_cloud_database_validation(): void
    {
        $mockChildRef = Mockery::mock(Reference::class);
        $mockChildRef->shouldReceive('set')->once()->andReturn($mockChildRef);

        $mockRef = Mockery::mock(Reference::class);
        $mockRef->shouldReceive('getValue')->once()->andReturn(['status' => 'active']);

        $mockDb = Mockery::mock(Database::class);
        $mockDb->shouldReceive('getReference')
            ->with('licenses/localhost')
            ->once()
            ->andReturn($mockRef);

        $mockDb->shouldReceive('getReference')
            ->with('licenses/localhost/last_seen_at')
            ->once()
            ->andReturn($mockChildRef);

        /** @var RemoteClient $client */
        $client = $this->app->make(RemoteClient::class);
        $client->setDatabase($mockDb);

        $res = $client->validate(['domain' => 'localhost']);

        $this->assertTrue($res['status']);
        $this->assertTrue($res['validated']);
        $this->assertNotEmpty($res['signature']);
    }

    public function test_runtime_manager_initialize_and_registration(): void
    {
        Http::fake([
            'https://invoixpro-default-rtdb.firebaseio.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        /** @var RuntimeManager $runtime */
        $runtime = $this->app->make(RuntimeManager::class);
        $res = $runtime->initialize();

        $this->assertTrue($res['status']);
        $this->assertTrue($runtime->registered());
    }

    public function test_runtime_manager_validation(): void
    {
        Http::fake([
            'https://invoixpro-default-rtdb.firebaseio.com/*' => Http::response(['status' => 'active'], 200),
        ]);

        /** @var RuntimeManager $runtime */
        $runtime = $this->app->make(RuntimeManager::class);
        $res1 = $runtime->validate();

        $this->assertTrue($res1['status']);
        $this->assertTrue($res1['validated']);
    }

    public function test_api_failure_handled_gracefully(): void
    {
        Http::fake([
            'https://invoixpro-default-rtdb.firebaseio.com/*' => Http::response(null, 500),
            'https://api.stackful.dev/v1/runtime/validate' => Http::response([
                'status' => false,
                'error' => 'Maintenance in progress',
            ], 500),
        ]);

        /** @var RemoteClient $client */
        $client = $this->app->make(RemoteClient::class);
        $res = $client->validate(['domain' => 'unknown-offline-domain.com']);

        $this->assertFalse($res['status']);
        $this->assertFalse($res['validated'] ?? false);
    }

    public function test_sensitive_information_is_sanitized_in_helpers(): void
    {
        $sensitive = [
            'product' => 'invoixpro',
            'api_token' => 'secret-value',
            'headers' => [
                'Authorization' => 'Bearer 12345',
            ],
            'normal_field' => 'visible',
        ];

        $sanitized = RuntimeHelper::sanitizeLogData($sensitive);

        $this->assertEquals('invoixpro', $sanitized['product']);
        $this->assertEquals('visible', $sanitized['normal_field']);
        $this->assertEquals('***REDACTED***', $sanitized['api_token']);
        $this->assertEquals('***REDACTED***', $sanitized['headers']['Authorization']);
    }

    public function test_configuration_resolver_authenticates_and_decrypts_successfully(): void
    {
        $this->assertTrue(ConfigurationResolver::verify());

        $config = ConfigurationResolver::resolve();
        $this->assertIsArray($config);
        $this->assertNotEmpty($config);
    }

    public function test_tampered_configuration_fails_integrity_and_throws(): void
    {
        $dataFile = dirname(__DIR__, 2) . '/src/Support/RuntimeStore.data';
        $original = file_get_contents($dataFile);

        try {
            $envelope = json_decode($original, true);
            $tamperedData = substr_replace($envelope['data'], 'X', 6, 1);
            $envelope['data'] = $tamperedData;
            file_put_contents($dataFile, json_encode($envelope));

            $this->assertFalse(ConfigurationResolver::verify());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Runtime configuration integrity verification failed');
            ConfigurationResolver::resolve();
        } finally {
            file_put_contents($dataFile, $original);
        }
    }
}
