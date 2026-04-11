<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * HealthController — Service Health Check Endpoints
 *
 * Provides liveness and readiness probes for Kubernetes/load balancers.
 * Also provides a full ecosystem health status for monitoring dashboards.
 */
class HealthController extends Controller
{
    /**
     * Simple liveness check — gateway is responding.
     * Uses: Kubernetes liveness probe, uptime monitors.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'service'   => config('app.name'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness check — gateway + all downstream services are healthy.
     * Uses: Kubernetes readiness probe, deployment verification.
     */
    public function ready(Request $request): JsonResponse
    {
        $checks  = [];
        $healthy = true;

        // Auth Service
        $authStatus = $this->checkService('auth-service', env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'));
        $checks['auth_service'] = $authStatus;
        if (!$authStatus['healthy']) $healthy = false;

        // User Service
        $userStatus = $this->checkService('user-service', env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api'));
        $checks['user_service'] = $userStatus;
        if (!$userStatus['healthy']) $healthy = false;

        // Cache (Redis)
        $checks['cache'] = $this->checkCache();
        if (!$checks['cache']['healthy']) $healthy = false;

        $httpStatus = $healthy ? 200 : 503;

        return response()->json([
            'status'    => $healthy ? 'healthy' : 'degraded',
            'service'   => config('app.name'),
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $httpStatus);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function checkService(string $name, string $baseUrl): array
    {
        $start = microtime(true);

        try {
            // Use a simple GET to the service root or well-known path
            $response = Http::timeout(3)->get($baseUrl);
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'name'       => $name,
                'healthy'    => true,
                'latency_ms' => $latencyMs,
                'status'     => $response->status(),
            ];
        } catch (\Exception $e) {
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'name'       => $name,
                'healthy'    => false,
                'latency_ms' => $latencyMs,
                'error'      => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:gateway:ping';
            \Illuminate\Support\Facades\Cache::put($key, 'pong', 10);
            $value = \Illuminate\Support\Facades\Cache::get($key);

            return [
                'name'    => 'redis-cache',
                'healthy' => $value === 'pong',
                'driver'  => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'name'    => 'redis-cache',
                'healthy' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
