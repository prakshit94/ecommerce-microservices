<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AuthClaimsService
 *
 * Fetches and caches JWT custom claims (roles + permissions) from user-service.
 * Uses Redis-backed cache to eliminate per-request HTTP calls during token generation.
 * Cache TTL matches JWT TTL to ensure claims stay fresh.
 */
class AuthClaimsService
{
    /**
     * Fetch JWT claims for a user — using Redis cache to avoid HTTP call per login.
     *
     * @param  int    $userId
     * @param  int    $ttlSeconds  JWT TTL in seconds (cache lives the same duration)
     * @return array{roles: array, permissions: array}
     */
    public function getClaims(int $userId, int $ttlSeconds = 3600): array
    {
        $cacheKey = "auth:claims:user:{$userId}";

        return Cache::remember($cacheKey, $ttlSeconds, function () use ($userId) {
            return $this->fetchFromUserService($userId);
        });
    }

    /**
     * Force-refresh the claims cache for a user (call after role/permission changes).
     */
    public function refreshClaims(int $userId): array
    {
        $cacheKey = "auth:claims:user:{$userId}";
        Cache::forget($cacheKey);

        $claims = $this->fetchFromUserService($userId);
        $jwtTtl = config('jwt.ttl', 60) * 60; // convert minutes to seconds
        Cache::put($cacheKey, $claims, $jwtTtl);

        return $claims;
    }

    /**
     * Invalidate claims cache for a user (call on logout/role change).
     */
    public function invalidateClaims(int $userId): void
    {
        Cache::forget("auth:claims:user:{$userId}");
    }

    /**
     * Fetch claims directly from user-service (no cache).
     */
    private function fetchFromUserService(int $userId): array
    {
        try {
            $response = Http::timeout(3)
                ->withHeaders([
                    'X-Gateway-Secret' => config('services.gateway.secret', env('GATEWAY_SECRET')),
                ])
                ->get(rtrim(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api'), '/') . '/users/' . $userId . '/claims');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'roles'       => $data['roles'] ?? [],
                    'permissions' => $data['permissions'] ?? [],
                ];
            }

            Log::warning("AuthClaimsService: user-service returned {$response->status()} for user #{$userId}");
        } catch (\Exception $e) {
            Log::error("AuthClaimsService: failed to fetch claims for user #{$userId} — " . $e->getMessage());
        }

        return ['roles' => [], 'permissions' => []];
    }
}
