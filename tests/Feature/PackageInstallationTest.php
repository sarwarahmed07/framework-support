<?php

namespace Stackful\FrameworkSupport\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Stackful\FrameworkSupport\FrameworkSupportServiceProvider;
use Stackful\FrameworkSupport\Runtime\RuntimeManager;
use Stackful\FrameworkSupport\Services\ApplicationService;
use Stackful\FrameworkSupport\Services\RemoteClient;
use Stackful\FrameworkSupport\Tests\TestCase;

class PackageInstallationTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(FrameworkSupportServiceProvider::class)
        );
    }

    public function test_application_service_bootstrap_flow(): void
    {
        Http::fake([
            'https://api.stackful.dev/v1/runtime/register' => Http::response([
                'status' => true,
                'installation_id' => '12345678-1234-4234-8234-123456789012',
                'token' => 'jwt-token-123',
            ], 200),
            'https://api.stackful.dev/v1/runtime/validate' => Http::response([
                'status' => true,
                'expires_at' => '2026-12-31T23:59:59Z',
                'signature' => 'sig-abc-123',
            ], 200),
        ]);

        /** @var ApplicationService $appService */
        $appService = $this->app->make(ApplicationService::class);
        $result = $appService->bootstrap();

        $this->assertTrue($result['status']);
        $this->assertTrue($result['validated']);
        $this->assertTrue($appService->isReady());
    }

    public function test_application_service_executes_runtime_operation(): void
    {
        Http::fake([
            'https://api.stackful.dev/v1/runtime/runtime' => Http::response([
                'status' => true,
                'data' => [
                    'authorized' => true,
                    'features' => ['advanced_invoicing', 'multi_currency'],
                ],
            ], 200),
        ]);

        /** @var ApplicationService $appService */
        $appService = $this->app->make(ApplicationService::class);
        $result = $appService->executeRuntimeOperation('fetch_features', ['tier' => 'pro']);

        $this->assertTrue($result['status']);
        $this->assertTrue($result['data']['authorized']);
        $this->assertContains('advanced_invoicing', $result['data']['features']);
    }
}
