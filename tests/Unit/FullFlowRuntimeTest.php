<?php

namespace Stackful\FrameworkSupport\Tests\Unit;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Mockery;
use RuntimeException;
use Stackful\FrameworkSupport\Runtime\NavigationHandler;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;
use Stackful\FrameworkSupport\Runtime\RuntimeSignal;
use Stackful\FrameworkSupport\Runtime\StateResolver;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Tests\TestCase;

class FullFlowRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_active_installation_with_fresh_cache(): void
    {
        /** @var StateResolver $stateResolver */
        $stateResolver = $this->app->make(StateResolver::class);
        $domainKey = 'localhost';
        $installationId = 'test-uuid-active-fresh';

        // Pre-populate fresh cache with active state
        Cache::put('framework_support_runtime_state_' . md5($domainKey), [
            'state' => 'active',
            'installation_id' => $installationId,
            'domain' => 'localhost',
            'checked_at' => time(),
        ], 10800);

        $signal = $stateResolver->resolveState($installationId);

        $this->assertNull($signal);
    }

    public function test_active_installation_with_expired_cache(): void
    {
        $installationId = 'test-uuid-active-expired';

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
        $signal = $stateResolver->resolveState($installationId);

        // Active state returns null (no redirect)
        $this->assertNull($signal);

        // Subsequent call must hit fresh cache and not call database again
        $signal2 = $stateResolver->resolveState($installationId);
        $this->assertNull($signal2);
    }

    public function test_inactive_installation_with_fresh_cache(): void
    {
        $domainKey = 'localhost';
        $installationId = 'test-uuid-inactive-fresh';
        $cachedSignal = new RuntimeSignal(
            'https://stackful.dev/suspended',
            $installationId,
            'localhost',
            time() - 10,
            time() + 3600,
            'nonce_fresh_123'
        );

        Cache::put('framework_support_runtime_state_' . md5($domainKey), [
            'state' => 'inactive',
            'installation_id' => $installationId,
            'domain' => 'localhost',
            'checked_at' => time(),
            'signal' => $cachedSignal,
        ], 10800);

        /** @var StateResolver $stateResolver */
        $stateResolver = $this->app->make(StateResolver::class);
        $signal = $stateResolver->resolveState($installationId);

        $this->assertInstanceOf(RuntimeSignal::class, $signal);
        $this->assertEquals('https://stackful.dev/suspended', $signal->getDestination());
    }

    public function test_inactive_installation_with_expired_cache(): void
    {
        $installationId = 'test-uuid-inactive-expired';

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
        $signal = $stateResolver->resolveState($installationId);

        $this->assertInstanceOf(RuntimeSignal::class, $signal);
        $this->assertEquals('https://www.codester.com/snsarwar09/', $signal->getDestination());
    }

    public function test_firebase_unavailable_fails_safely_without_redirect(): void
    {
        $mockDb = Mockery::mock(Database::class);
        $mockDb->shouldReceive('getReference')
            ->andThrow(new RuntimeException('Firebase network timeout'));

        /** @var RemoteClient $remoteClient */
        $remoteClient = $this->app->make(RemoteClient::class);
        $remoteClient->setDatabase($mockDb);

        /** @var StateResolver $stateResolver */
        $stateResolver = $this->app->make(StateResolver::class);
        $signal = $stateResolver->resolveState('test-uuid-timeout');

        // Must fail open/safe without returning a redirect signal
        $this->assertNull($signal);
    }

    public function test_tampered_payload_in_firebase_returns_null_fail_safe(): void
    {
        $installationId = 'test-uuid-tampered';
        $domain = 'localhost';

        $envelope = RuntimeSignal::createEnvelope([
            'destination' => 'https://stackful.dev/notice',
            'installation_id' => $installationId,
            'domain' => $domain,
            'issued_at' => time(),
            'expires_at' => time() + 3600,
            'nonce' => 'nonce_123',
        ]);
        $envelope['data'] = substr_replace($envelope['data'], 'X', 5, 1); // Tampered

        $mockRef = Mockery::mock(Reference::class);
        $mockRef->shouldReceive('getValue')->once()->andReturn([
            'status' => 'inactive',
            'bound_domain' => $domain,
            'payload' => $envelope,
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
        $signal = $stateResolver->resolveState($installationId);

        // Must reject tampered payload and fail safely (null)
        $this->assertNull($signal);
    }

    public function test_navigation_handler_ignores_cli_requests(): void
    {
        $stateResolver = Mockery::mock(StateResolver::class);
        $stateResolver->shouldNotReceive('resolveState');

        $runtimeManager = Mockery::mock(RuntimeManager::class);

        $handler = new NavigationHandler($this->app, $runtimeManager, $stateResolver);
        $request = Request::create('https://example.com/test', 'GET');

        $response = $handler->handle($request, function ($req) {
            return new \Illuminate\Http\Response('CLI response');
        });

        $this->assertEquals('CLI response', $response->getContent());
    }
}
