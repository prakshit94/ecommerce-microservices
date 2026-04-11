<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Str;

/**
 * JwtAuthGateway — Enterprise JWT Authentication Middleware
 *
 * Responsibilities:
 * 1. Strip all client-injected X-User-* headers (prevent spoofing)
 * 2. Verify JWT signature & expiry
 * 3. Validate session via auth-service (Redis-cached for 30s)
 * 4. Inject authenticated user context headers for downstream services
 * 5. Clock skew attack detection
 * 6. Attach X-Request-Id for distributed tracing
 */
class JwtAuthGateway
{
    // JWT validation cache TTL (30 seconds — balance between performance & revocation speed)
    private int $validationCacheTtl = 30;

    public function handle(Request $request, Closure $next): Response
    {
        // Attach distributed tracing ID to every request
        $requestId = Str::uuid()->toString();
        $request->headers->set('X-Request-Id', $requestId);

        // ── STRIP SPOOFED HEADERS ─────────────────────────────────────────
        $request->headers->remove('x-user-id');
        $request->headers->remove('x-user-roles');
        $request->headers->remove('x-user-permissions');
        $request->headers->remove('x-organization-id');
        $request->headers->remove('x-user-email');
        $request->headers->remove('x-forwarded-user');

        try {
            // ── VERIFY JWT ────────────────────────────────────────────────
            if (!$payload = JWTAuth::parseToken()->getPayload()) {
                return $this->unauthorized('Token not provided or invalid.');
            }

            // ── CLOCK SKEW CHECK ──────────────────────────────────────────
            $issuedAt = $payload->get('iat');
            if ($issuedAt && abs(time() - $issuedAt) > 300) { // 5-minute clock tolerance
                Log::warning("JwtAuthGateway: Clock skew attack suspected. IAT={$issuedAt}, NOW=" . time());
                return $this->unauthorized('Token clock skew detected.');
            }

            // ── SESSION REVOCATION CHECK (Redis-cached) ───────────────────
            $jti = $payload->get('jti');
            if ($jti) {
                $cacheKey      = "gateway:token_valid:{$jti}";
                $validationData = Cache::remember($cacheKey, $this->validationCacheTtl, function () use ($jti) {
                    return $this->validateWithAuthService($jti);
                });

                if (!$validationData || !$validationData['valid']) {
                    Cache::forget($cacheKey); // Don't cache invalid sessions
                    $reason = $validationData['reason'] ?? 'session_invalid';
                    return $this->unauthorized("Token revoked or expired. ({$reason})", 'TOKEN_REVOKED');
                }
            }

            // ── EXTRACT CLAIMS & INJECT DOWNSTREAM HEADERS ────────────────
            $userId      = $payload->get('sub');
            $roles       = $payload->get('roles') ?? [];
            $permissions = $payload->get('permissions') ?? [];
            $isActive    = $payload->get('is_active') ?? true;
            $mfaEnabled  = $payload->get('mfa_enabled') ?? false;

            // Block inactive users at gateway level
            if (!$isActive) {
                return $this->forbidden('Account is deactivated.');
            }

            // Inject verified context headers for downstream microservices
            $request->headers->set('X-User-Id', (string) $userId);
            $request->headers->set('X-User-Email', (string) ($payload->get('email') ?? ''));
            $request->headers->set('X-Request-Id', $requestId);
            $request->headers->set('X-Device-Type', (string) ($validationData['device_type'] ?? 'web'));

            if (!empty($roles)) {
                $request->headers->set('X-User-Roles', json_encode($roles));
            }
            if (!empty($permissions)) {
                $request->headers->set('X-User-Permissions', json_encode($permissions));
            }

        } catch (JWTException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (\Exception $e) {
            Log::error('JwtAuthGateway: unexpected error — ' . $e->getMessage());
            return $this->unauthorized('Authentication failed.');
        }

        $response = $next($request);

        // Add tracing header to response
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Call auth-service to validate the JTI (session revocation check).
     * Returns array with 'valid' key and optional 'reason', 'device_type'.
     */
    private function validateWithAuthService(string $jti): array
    {
        try {
            $url      = env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth/validate';
            $response = Http::timeout(3)
                ->withHeaders(['X-Gateway-Secret' => env('GATEWAY_SECRET')])
                ->post($url, ['jti' => $jti]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("JwtAuthGateway: auth-service returned {$response->status()} for JTI validation.");
            return ['valid' => false, 'reason' => 'auth_service_error'];
        } catch (\Exception $e) {
            // If auth-service is unreachable, fall through (fail-open for resilience)
            // In strict mode: return ['valid' => false, 'reason' => 'auth_service_unavailable'];
            Log::warning("JwtAuthGateway: auth-service unreachable — " . $e->getMessage());
            return ['valid' => true, 'reason' => 'auth_service_bypass', 'device_type' => 'unknown'];
        }
    }

    private function unauthorized(string $message, string $code = 'UNAUTHENTICATED'): Response
    {
        return response()->json([
            'error'   => $message,
            'code'    => $code,
        ], 401);
    }

    private function forbidden(string $message, string $code = 'FORBIDDEN'): Response
    {
        return response()->json([
            'error' => $message,
            'code'  => $code,
        ], 403);
    }
}
