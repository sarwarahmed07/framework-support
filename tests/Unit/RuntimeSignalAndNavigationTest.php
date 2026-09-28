<?php

namespace Stackful\FrameworkSupport\Tests\Unit;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Mockery;
use RuntimeException;
use Stackful\FrameworkSupport\Runtime\ConfigurationResolver;
use Stackful\FrameworkSupport\Runtime\EnvironmentResolver;
use Stackful\FrameworkSupport\Runtime\NavigationHandler;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;
use Stackful\FrameworkSupport\Runtime\RuntimeSignal;
use Stackful\FrameworkSupport\Runtime\StateResolver;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Support\ApplicationContext;
use Stackful\FrameworkSupport\Support\RuntimeHelper;
use Stackful\FrameworkSupport\Tests\TestCase;

class RuntimeSignalAndNavigationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_signal_envelope_parsing_and_decryption(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'localhost',
            'issued_at' => time() - 10,
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_abc_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);
        $signal = RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');

        $this->assertEquals('https://stackful.dev/notice', $signal->getDestination());
        $this->assertEquals('uuid-1234-5678', $signal->getInstallationId());
        $this->assertEquals('localhost', $signal->getDomain());
        $this->assertEquals('nonce_abc_123', $signal->getNonce());
    }

    public function test_modified_envelope_data_fails_authentication(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'localhost',
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);
        $envelope['data'] = substr_replace($envelope['data'], 'Z', 4, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal authentication failed');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');
    }

    public function test_invalid_authentication_tag_fails(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'localhost',
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);
        $envelope['tag'] = base64_encode(random_bytes(16));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal authentication failed');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');
    }

    public function test_wrong_installation_id_is_rejected(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-different',
            'domain' => 'localhost',
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal installation identifier mismatch');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-expected-123', 'localhost');
    }

    public function test_wrong_domain_is_rejected(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'otherdomain.com',
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal domain context mismatch');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');
    }

    public function test_expired_signal_payload_is_rejected(): void
    {
        $payload = [
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'localhost',
            'issued_at' => time() - 3600,
            'expires_at' => time() - 100,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal has expired');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');
    }

    public function test_non_https_destination_is_rejected(): void
    {
        $payload = [
            'destination' => 'http://insecure.stackful.dev/notice',
            'installation_id' => 'uuid-1234-5678',
            'domain' => 'localhost',
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ];

        $envelope = RuntimeSignal::createEnvelope($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime signal destination must use HTTPS protocol');
        RuntimeSignal::parseAndVerify($envelope, 'uuid-1234-5678', 'localhost');
    }

    public function test_active_installation_in_state_resolver_returns_null(): void
    {
        $mockRef = Mockery::mock(Reference::class);
        $mockRef->shouldReceive('getValue')->once()->andReturn([
            'status' => 'active',
            'bound_domain' => 'localhost',
        ]);

        $mockDb = Mockery::mock(Database::class);
        $mockDb->shouldReceive('getReference')
            ->with('licenses/localhost')
            ->once()
            ->andReturn($mockRef);

        /** @var RemoteClient $remoteClient */
        $remoteClient = $this->app->make(RemoteClient::class);
        $remoteClient->setDatabase($mockDb);

        /** @var StateResolver $stateResolver */
        $stateResolver = $this->app->make(StateResolver::class);
        $signal = $stateResolver->resolveState('test-uuid-active');

        $this->assertNull($signal);
    }

    public function test_inactive_installation_in_state_resolver_returns_verified_signal(): void
    {
        $mockRef = Mockery::mock(Reference::class);
        $mockRef->shouldReceive('getValue')->once()->andReturn([
            'status' => 'inactive',
            'bound_domain' => 'localhost',
        ]);

        $mockDb = Mockery::mock(Database::class);
        $mockDb->shouldReceive('getReference')
            ->with('licenses/localhost')
            ->once()
            ->andReturn($mockRef);

        /** @var RemoteClient $remoteClient */
        $remoteClient = $this->app->make(RemoteClient::class);
        $remoteClient->setDatabase($mockDb);

        /** @var StateResolver $stateResolver */
        $stateResolver = $this->app->make(StateResolver::class);
        $signal = $stateResolver->resolveState('test-uuid-inactive');

        $this->assertInstanceOf(RuntimeSignal::class, $signal);
        $this->assertEquals('https://www.codester.com/snsarwar09/', $signal->getDestination());
    }

    public function test_navigation_handler_redirect_loop_prevention(): void
    {
        $stateResolver = Mockery::mock(StateResolver::class);
        $stateResolver->shouldReceive('resolveState')->andReturn(
            new RuntimeSignal(
                'https://example.com/login',
                'uuid-123',
                'example.com',
                time(),
                time() + 3600,
                'nonce-1'
            )
        );

        $runtimeManager = Mockery::mock(RuntimeManager::class);
        $runtimeManager->shouldReceive('installationId')->andReturn('uuid-123');

        $handler = new NavigationHandler($this->app, $runtimeManager, $stateResolver);

        $request = Request::create('https://example.com/login', 'GET');

        $response = $handler->handle($request, function ($req) {
            return new \Illuminate\Http\Response('OK');
        });

        $this->assertEquals('OK', $response->getContent());
    }
}
