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

            // Centralized session revocation check
            $jti = $payload->get('jti');
            if ($jti) {
                try {
                    $validationUrl = env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth/validate';
                    $response = \Illuminate\Support\Facades\Http::timeout(3)
                        ->post($validationUrl, ['jti' => $jti]);
                    
                    if (!$response->successful() || !$response->json('valid')) {
                        return response()->json(['error' => 'Invalid or Revoked Token', 'message' => 'Session expired or revoked'], 401);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Token validation HTTP check failed: ' . $e->getMessage());
                }
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
