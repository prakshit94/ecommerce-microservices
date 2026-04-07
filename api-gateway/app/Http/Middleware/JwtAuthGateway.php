<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class JwtAuthGateway
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Strip any client-injected headers to prevent spoofing
        $request->headers->remove('x-user-id');
        $request->headers->remove('x-user-roles');
        $request->headers->remove('x-user-permissions');

        try {
            // Verify JWT Token
            if (!$payload = JWTAuth::parseToken()->getPayload()) {
                return response()->json(['error' => 'Token not provided'], 401);
            }

            // Extract claims
            $userId = $payload->get('sub');
            $roles = $payload->get('roles') ?? [];
            $permissions = $payload->get('permissions') ?? [];

            // Add headers to forward to other microservices
            $request->headers->set('X-User-Id', $userId);
            if (!empty($roles)) {
                $request->headers->set('X-User-Roles', json_encode($roles));
            }
            if (!empty($permissions)) {
                $request->headers->set('X-User-Permissions', json_encode($permissions));
            }

        } catch (JWTException $e) {
            return response()->json([
                'error' => 'Unauthenticated or Invalid Token',
                'message' => $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
