<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Internal Service-to-Service Bypass:
        // If X-Internal-Sync is set, this is a trusted internal call (e.g. auth-service syncing a new user).
        // X-Gateway-Secret is always required, so this is still protected from outside traffic.
        if ($request->hasHeader('X-Internal-Sync')) {
            return $next($request);
        }

        // If there's no X-User-Id, the request has not been user-authenticated by the gateway.
        // Since all routes already require X-Gateway-Secret, we trust this as an internal service call.
        if (!$request->hasHeader('X-User-Id')) {
            return $next($request);
        }

        // Get permissions from the header injected by the API Gateway
        $permissionsJson = $request->header('X-User-Permissions');

        if (!$permissionsJson) {
            return response()->json(['error' => 'Forbidden. No permissions provided.'], 403);
        }

        $permissions = json_decode($permissionsJson, true);

        if (!is_array($permissions) || !in_array($permission, $permissions)) {
            return response()->json([
                'error'               => 'Forbidden. Insufficient permissions.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
