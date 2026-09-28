<?php

namespace Stackful\FrameworkSupport\Runtime;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class NavigationHandler
{
    protected Application $app;
    protected RuntimeManager $runtimeManager;
    protected StateResolver $stateResolver;

    public function __construct(
        Application $app,
        RuntimeManager $runtimeManager,
        StateResolver $stateResolver
    ) {
        $this->app = $app;
        $this->runtimeManager = $runtimeManager;
        $this->stateResolver = $stateResolver;
    }

    /**
     * Handle incoming HTTP request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. HTTP ONLY: Never run during CLI, Artisan, migrations, queues, tests, or scheduled jobs
        if ($this->app->runningInConsole() || $this->app->runningUnitTests() || PHP_SAPI === 'cli') {
            return $next($request);
        }

        // 2. Ignore non-browser / internal requests (AJAX, JSON APIs, Livewire, etc.)
        if ($request->is('_framework/*', 'livewire/*', 'broadcasting/*', 'sanctum/*') || $request->ajax() || $request->wantsJson()) {
            return $next($request);
        }

        try {
            $installationId = $this->runtimeManager->installationId();
            $signal = $this->stateResolver->resolveState($installationId);

            // If active, offline, or invalid: $signal is NULL -> continues application without interruption
            if ($signal instanceof RuntimeSignal) {
                $destination = $signal->getDestination();

                // Prevent redirect loops if already on destination URL
                $currentUrl = $request->fullUrl();
                if ($this->isSameUrl($currentUrl, $destination)) {
                    return $next($request);
                }

                return new RedirectResponse($destination, 302, [
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }
        } catch (Throwable) {
            // Fail-safe: Never disrupt the host application
        }

        return $next($request);
    }

    /**
     * Compare two URLs ignoring query string differences or trailing slashes.
     */
    protected function isSameUrl(string $url1, string $url2): bool
    {
        $parsed1 = parse_url($url1);
        $parsed2 = parse_url($url2);

        $host1 = $parsed1['host'] ?? '';
        $host2 = $parsed2['host'] ?? '';
        $path1 = rtrim($parsed1['path'] ?? '/', '/');
        $path2 = rtrim($parsed2['path'] ?? '/', '/');

        return strcasecmp($host1, $host2) === 0 && strcasecmp($path1, $path2) === 0;
    }
}
