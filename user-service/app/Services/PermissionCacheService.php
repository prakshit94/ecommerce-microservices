<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

/**
 * PermissionCacheService
 *
 * Provides fine-grained, per-user permission caching in Redis.
 * Spatie's default cache is application-wide; this service provides
 * per-user caching that can be surgically invalidated when a user's
 * roles or permissions change without flushing the entire permission cache.
 */
class PermissionCacheService
{
    private int $ttl = 3600; // 1 hour

    public function __construct()
    {
        // config('permission.cache.expiration_time') returns a DateInterval object.
        // We read the seconds directly from the env variable instead to get a plain integer.
        $this->ttl = (int) env('PERMISSION_CACHE_EXPIRATION_SECONDS', 3600);
    }

    /**
     * Get all permission names for a user (cached).
     */
    public function getUserPermissions(int $userId): array
    {
        return Cache::remember(
            $this->userPermissionsKey($userId),
            $this->ttl,
            function () use ($userId) {
                $user = User::find($userId);
                if (!$user) return [];
                return $user->getAllPermissions()->pluck('name')->toArray();
            }
        );
    }

    /**
     * Get all role names for a user (cached).
     */
    public function getUserRoles(int $userId): array
    {
        return Cache::remember(
            $this->userRolesKey($userId),
            $this->ttl,
            function () use ($userId) {
                $user = User::find($userId);
                if (!$user) return [];
                return $user->getRoleNames()->toArray();
            }
        );
    }

    /**
     * Get full claims (roles + permissions) for a user (cached).
     */
    public function getUserClaims(int $userId): array
    {
        return Cache::remember(
            $this->userClaimsKey($userId),
            $this->ttl,
            function () use ($userId) {
                $user = User::find($userId);
                if (!$user) return ['roles' => [], 'permissions' => []];
                return [
                    'roles'       => $user->getRoleNames()->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                ];
            }
        );
    }

    /**
     * Invalidate all cached data for a user.
     * Call this when user's roles or direct permissions change.
     */
    public function invalidateUser(int $userId): void
    {
        Cache::forget($this->userPermissionsKey($userId));
        Cache::forget($this->userRolesKey($userId));
        Cache::forget($this->userClaimsKey($userId));

        // Also flush Spatie's global permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Invalidate all users' permission caches.
     * Call this when a role's permissions change (affects all users with that role).
     */
    public function invalidateAll(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Note: In production with Redis, flush cache tags instead
    }

    private function userPermissionsKey(int $userId): string
    {
        return "user_service:permissions:user:{$userId}";
    }

    private function userRolesKey(int $userId): string
    {
        return "user_service:roles:user:{$userId}";
    }

    private function userClaimsKey(int $userId): string
    {
        return "user_service:claims:user:{$userId}";
    }
}
